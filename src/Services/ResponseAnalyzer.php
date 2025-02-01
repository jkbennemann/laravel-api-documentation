<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Spatie\LaravelData\Data;
use Throwable;

class ResponseAnalyzer
{
    private array $relationshipTypes;

    private array $methodTypes;

    private array $paginationConfig;

    private bool $enabled;

    private ?string $currentResource = null;

    public function __construct(private readonly Repository $configuration)
    {
        $this->enabled = $configuration->get('api-documentation.smart_responses.enabled', true);
        $this->relationshipTypes = $configuration->get('api-documentation.smart_responses.relationship_types', []);
        $this->methodTypes = $configuration->get('api-documentation.smart_responses.method_types', []);
        $this->paginationConfig = $configuration->get('api-documentation.smart_responses.pagination', []);
    }

    /**
     * Analyze a controller method to determine its response structure
     */
    public function analyzeMethodResponse(string $controller, string $method): array
    {
        if (! $this->enabled) {
            return $this->getDefaultResponse();
        }

        try {
            $reflectionMethod = new ReflectionMethod($controller, $method);
            $returnType = $reflectionMethod->getReturnType();

            if ($returnType === null) {
                return $this->getDefaultResponse();
            }

            $returnTypeName = $returnType->getName();

            // Check if it's a Resource response
            if (is_subclass_of($returnTypeName, JsonResource::class)) {
                return $this->analyzeResourceResponse($returnTypeName);
            }

            // Check if it's a Data object response
            if (is_subclass_of($returnTypeName, Data::class)) {
                return $this->analyzeDataResponse($returnTypeName);
            }

            // Check if it's a collection
            if (is_subclass_of($returnTypeName, ResourceCollection::class)) {
                return $this->analyzeCollectionResponse($returnTypeName);
            }

            return $this->getDefaultResponse();
        } catch (Throwable) {
            return $this->getDefaultResponse();
        }
    }

    /**
     * Analyze a Resource class to determine its structure
     */
    private function analyzeResourceResponse(string $resourceClass): array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $toArrayMethod = $reflection->getMethod('toArray');

            // Get the return type if available
            $returnType = $toArrayMethod->getReturnType();

            // Check if the resource has a defined structure
            if ($returnType !== null) {
                return [
                    'type' => 'object',
                    'properties' => $this->extractResourceProperties($resourceClass),
                ];
            }

            return [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'description' => 'Resource data',
                    ],
                ],
            ];
        } catch (Throwable) {
            return $this->getDefaultResponse();
        }
    }

    /**
     * Analyze a Data class to determine its structure
     */
    private function analyzeDataResponse(string $dataClass): array
    {
        try {
            $reflection = new ReflectionClass($dataClass);
            $properties = [];

            foreach ($reflection->getProperties() as $property) {
                $type = $property->getType();
                $properties[$property->getName()] = [
                    'type' => $this->mapPhpTypeToOpenApi($type?->getName() ?? 'mixed'),
                    'description' => $this->extractPropertyDescription($property),
                ];
            }

            return [
                'type' => 'object',
                'properties' => $properties,
            ];
        } catch (Throwable) {
            return $this->getDefaultResponse();
        }
    }

    /**
     * Analyze a ResourceCollection to determine its structure
     */
    private function analyzeCollectionResponse(string $collectionClass): array
    {
        try {
            $reflection = new ReflectionClass($collectionClass);

            // Check if it's a paginated response
            if (is_subclass_of($collectionClass, AbstractPaginator::class)) {
                return $this->getPaginatedResponse($collectionClass);
            }

            return [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => $this->extractCollectionProperties($collectionClass),
                        ],
                    ],
                ],
            ];
        } catch (Throwable) {
            return $this->getDefaultResponse();
        }
    }

    /**
     * Get the structure for a paginated response
     */
    private function getPaginatedResponse(string $collectionClass): array
    {
        if (! $this->paginationConfig['enabled'] ?? true) {
            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $this->extractCollectionProperties($collectionClass),
                ],
            ];
        }

        $structure = [
            'type' => 'object',
            'properties' => [],
        ];

        if ($this->paginationConfig['structure']['data'] ?? true) {
            $structure['properties']['data'] = [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $this->extractCollectionProperties($collectionClass),
                ],
            ];
        }

        if ($this->paginationConfig['structure']['meta'] ?? true) {
            $structure['properties']['meta'] = [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer'],
                    'from' => ['type' => 'integer'],
                    'last_page' => ['type' => 'integer'],
                    'path' => ['type' => 'string'],
                    'per_page' => ['type' => 'integer'],
                    'to' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                ],
            ];
        }

        if ($this->paginationConfig['structure']['links'] ?? true) {
            $structure['properties']['links'] = [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => 'string'],
                    'last' => ['type' => 'string'],
                    'prev' => ['type' => 'string', 'nullable' => true],
                    'next' => ['type' => 'string', 'nullable' => true],
                ],
            ];
        }

        return $structure;
    }

    /**
     * Extract properties from a Resource class
     */
    private function extractResourceProperties(string $resourceClass): array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $properties = [];

            // 1. First check for Parameter attributes on the class
            $classAttributes = $reflection->getAttributes(Parameter::class);
            if (! empty($classAttributes)) {
                foreach ($classAttributes as $attribute) {
                    $parameter = $attribute->newInstance();
                    $properties[$parameter->name] = [
                        'type' => $this->mapPhpTypeToOpenApi($parameter->type),
                        'description' => $parameter->description,
                        'required' => $parameter->required,
                    ];

                    if ($parameter->format) {
                        $properties[$parameter->name]['format'] = $parameter->format;
                    }

                    if ($parameter->deprecated) {
                        $properties[$parameter->name]['deprecated'] = true;
                    }

                    if ($parameter->example !== null) {
                        $properties[$parameter->name]['example'] = $parameter->example;
                    }
                }

                return $properties;
            }

            // 2. Check for Parameter attributes on the toArray method
            $toArrayMethod = $reflection->getMethod('toArray');
            $methodAttributes = $toArrayMethod->getAttributes(Parameter::class);
            if (! empty($methodAttributes)) {
                foreach ($methodAttributes as $attribute) {
                    $parameter = $attribute->newInstance();
                    $properties[$parameter->name] = [
                        'type' => $this->mapPhpTypeToOpenApi($parameter->type),
                        'description' => $parameter->description,
                        'required' => $parameter->required,
                    ];

                    if ($parameter->format) {
                        $properties[$parameter->name]['format'] = $parameter->format;
                    }

                    if ($parameter->deprecated) {
                        $properties[$parameter->name]['deprecated'] = true;
                    }

                    if ($parameter->example !== null) {
                        $properties[$parameter->name]['example'] = $parameter->example;
                    }
                }

                return $properties;
            }

            // 3. Then try to get properties from PHPDoc
            $docComment = $reflection->getDocComment();
            if ($docComment) {
                preg_match_all('/@property\s+([^\s]+)\s+\$([^\s]+)(?:\s+(.*))?/', $docComment, $matches);

                foreach ($matches[2] as $index => $propertyName) {
                    $properties[$propertyName] = [
                        'type' => $this->mapPhpTypeToOpenApi($matches[1][$index]),
                        'description' => $matches[3][$index] ?? '',
                    ];
                }

                if (! empty($properties)) {
                    return $properties;
                }
            }

            // 4. If no other documentation found, analyze toArray method
            return $this->analyzeToArrayMethod($resourceClass);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Analyze toArray method using PHP-Parser
     */
    private function analyzeToArrayMethod(string $resourceClass): array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $method = $reflection->getMethod('toArray');
            $fileName = $reflection->getFileName();

            if (! $fileName) {
                return [];
            }

            $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
            $code = file_get_contents($fileName);
            $ast = $parser->parse($code);

            $nodeFinder = new NodeFinder;
            $properties = [];

            // Find the toArray method node
            $methodNode = $nodeFinder->findFirst($ast, function ($node) {
                return $node instanceof ClassMethod
                    && $node->name->toString() === 'toArray';
            });

            if (! $methodNode) {
                return [];
            }

            // Find return statement in the method
            $returnNode = $nodeFinder->findFirst($methodNode, function ($node) {
                return $node instanceof Return_;
            });

            if (! $returnNode || ! $returnNode->expr instanceof Expr\Array_) {
                return [];
            }

            // Analyze array items
            foreach ($returnNode->expr->items as $item) {
                if (! $item || ! $item->key instanceof String_) {
                    continue;
                }

                $propertyName = $item->key->value;
                $propertyType = $this->inferTypeFromValue($item->value);

                $properties[$propertyName] = [
                    'type' => $propertyType['type'],
                    'description' => '',
                ];

                if (isset($propertyType['items'])) {
                    $properties[$propertyName]['items'] = $propertyType['items'];
                }

                if (isset($propertyType['nullable']) && $propertyType['nullable']) {
                    $properties[$propertyName]['nullable'] = true;
                }
            }

            return $properties;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Infer OpenAPI type from PHP-Parser node
     */
    private function inferTypeFromValue(Expr $value): array
    {
        if ($value instanceof Expr\PropertyFetch) {
            // Handle property access ($this->property)
            return $this->inferTypeFromPropertyAccess($value);
        }

        if ($value instanceof Expr\MethodCall) {
            // Handle method calls ($this->method())
            return $this->inferTypeFromMethodCall($value);
        }

        if ($value instanceof Expr\Array_) {
            // Handle array literals
            return [
                'type' => 'array',
                'items' => ['type' => 'string'], // Default to string if can't determine item type
            ];
        }

        if ($value instanceof Expr\Ternary) {
            // Handle ternary operations (possibly nullable values)
            return ['type' => 'string', 'nullable' => true];
        }

        // Default fallback
        return ['type' => 'string'];
    }

    /**
     * Infer type from property access
     */
    private function inferTypeFromPropertyAccess(Expr\PropertyFetch $propertyFetch): array
    {
        if (! $propertyFetch->name instanceof Identifier) {
            return ['type' => 'string'];
        }

        $propertyName = $propertyFetch->name->toString();

        try {
            if ($propertyFetch->var instanceof Expr\Variable && $propertyFetch->var->name === 'this') {
                $reflection = new ReflectionClass($this->currentResource);
                if ($reflection->hasProperty($propertyName)) {
                    $property = $reflection->getProperty($propertyName);
                    $type = $property->getType();

                    if ($type) {
                        return [
                            'type' => $this->mapPhpTypeToOpenApi($type->getName()),
                            'nullable' => $type->allowsNull(),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // Fallback to string if type inference fails
        }

        return ['type' => 'string'];
    }

    /**
     * Infer type from method call
     */
    private function inferTypeFromMethodCall(Expr\MethodCall $methodCall): array
    {
        if (! $methodCall->name instanceof Identifier) {
            return ['type' => 'string'];
        }

        $methodName = $methodCall->name->toString();

        // Check configured method types
        if (isset($this->methodTypes[$methodName])) {
            return $this->methodTypes[$methodName];
        }

        // Check relationship methods
        if (isset($this->relationshipTypes[$methodName])) {
            return $this->relationshipTypes[$methodName];
        }

        // Handle common Laravel relationship methods
        if (in_array($methodName, ['hasOne', 'belongsTo', 'morphOne'])) {
            return ['type' => 'object'];
        }

        if (in_array($methodName, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany'])) {
            return [
                'type' => 'array',
                'items' => ['type' => 'object'],
            ];
        }

        // Handle date formatting methods
        if (in_array($methodName, ['toDateString', 'toDateTimeString', 'format'])) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        // Default fallback
        return ['type' => 'string'];
    }

    /**
     * Extract properties from a Collection
     */
    private function extractCollectionProperties(string $collectionClass): array
    {
        try {
            $reflection = new ReflectionClass($collectionClass);
            $resourceType = $this->getResourceType($reflection);

            if ($resourceType) {
                return $this->extractResourceProperties($resourceType);
            }

            return [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get the resource type from a collection
     */
    private function getResourceType(ReflectionClass $reflection): ?string
    {
        try {
            $constructor = $reflection->getConstructor();
            if ($constructor) {
                $params = $constructor->getParameters();
                foreach ($params as $param) {
                    $type = $param->getType();
                    if ($type && is_subclass_of($type->getName(), JsonResource::class)) {
                        return $type->getName();
                    }
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Map PHP types to OpenAPI types
     */
    private function mapPhpTypeToOpenApi(string $phpType): string
    {
        return match ($phpType) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }

    /**
     * Extract property description from PHPDoc
     */
    private function extractPropertyDescription(ReflectionProperty $property): string
    {
        $docComment = $property->getDocComment();
        if ($docComment) {
            preg_match('/@var[^@]*(?=@|$)/s', $docComment, $matches);
            if (isset($matches[0])) {
                return trim(preg_replace('/@var\s+[^\s]+\s*/', '', $matches[0]));
            }
        }

        return '';
    }

    /**
     * Get default response structure
     */
    private function getDefaultResponse(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'description' => 'Response data',
                ],
            ],
        ];
    }

    public function analyzeResponse(ReflectionMethod $method): ?Schema
    {
        if (! $this->enabled) {
            return null;
        }

        $returnType = $method->getReturnType();
        if (! $returnType) {
            return null;
        }

        $typeName = $returnType->getName();

        // Handle collection responses
        if (is_a($typeName, ResourceCollection::class, true)) {
            $response = $this->analyzeCollectionResponse($typeName);

            return $this->convertArrayToSchema($response);
        }

        // Handle paginated responses
        if (is_a($typeName, AbstractPaginator::class, true)) {
            $response = $this->getPaginatedResponse($typeName);

            return $this->convertArrayToSchema($response);
        }

        // Handle array responses
        if ($typeName === 'array') {
            return new Schema([
                'type' => 'array',
                'items' => new Schema([
                    'type' => 'object',
                ]),
            ]);
        }

        // Handle Data object responses
        if (is_a($typeName, Data::class, true)) {
            $response = $this->analyzeDataResponse($typeName);

            return $this->convertArrayToSchema($response);
        }

        return new Schema([
            'type' => 'object',
        ]);
    }

    public function analyzeErrorResponses(ReflectionMethod $method): array
    {
        if (! $this->enabled) {
            return [];
        }

        $responses = [];

        // Add validation error response if the method has a form request
        $requestClass = $this->findRequestClass($method);
        if ($requestClass) {
            $responses['422'] = new Schema([
                'type' => 'object',
                'properties' => [
                    'message' => new Schema([
                        'type' => 'string',
                    ]),
                    'errors' => new Schema([
                        'type' => 'object',
                        'additionalProperties' => new Schema([
                            'type' => 'array',
                            'items' => new Schema([
                                'type' => 'string',
                            ]),
                        ]),
                    ]),
                ],
            ]);
        }

        return $responses;
    }

    private function findRequestClass(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type) {
                continue;
            }

            $typeName = $type->getName();
            if (is_a($typeName, Request::class, true)) {
                return $typeName;
            }
        }

        return null;
    }

    private function convertArrayToSchema(array $array): Schema
    {
        $schema = new Schema([]);

        foreach ($array as $key => $value) {
            if ($key === 'type') {
                $schema->type = $value;
            } elseif ($key === 'properties') {
                $properties = [];
                foreach ($value as $propName => $propValue) {
                    $properties[$propName] = $this->convertArrayToSchema($propValue);
                }
                $schema->properties = $properties;
            } elseif ($key === 'items') {
                $schema->items = $this->convertArrayToSchema($value);
            } elseif ($key === 'description') {
                $schema->description = $value;
            }
        }

        return $schema;
    }
}
