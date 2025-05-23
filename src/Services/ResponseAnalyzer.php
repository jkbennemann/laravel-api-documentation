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
     * Analyze a controller method to determine its response types
     * @throws \ReflectionException
     */
    public function analyzeControllerMethod(string $controller, string $method): array
    {
        if (!class_exists($controller)) {
            return [];
        }

        $reflection = new ReflectionMethod($controller, $method);
        $returnType = $reflection->getReturnType();

        if (!$returnType) {
            return $this->extractFromDocBlock($reflection);
        }

        $typeName = $returnType->getName();

        // Handle union types (PHP 8+)
        if (method_exists($returnType, 'getTypes')) {
            return $this->handleUnionTypes($returnType->getTypes());
        }

        // Check if it's a Spatie Data object
        if (class_exists($typeName) && is_subclass_of($typeName, Data::class)) {
            return $this->analyzeSpatieDataObject($typeName);
        }

        return [
            'type' => $this->mapPhpTypeToOpenApi($typeName),
            'format' => $this->getFormatForType($typeName),
        ];
    }

    /**
     * Handle union types by returning all possible types
     */
    protected function handleUnionTypes(array $types): array
    {
        $responseTypes = [];

        foreach ($types as $type) {
            $typeName = $type->getName();
            $responseTypes[] = [
                'type' => $this->mapPhpTypeToOpenApi($typeName),
                'format' => $this->getFormatForType($typeName),
            ];
        }

        return [
            'oneOf' => $responseTypes,
        ];
    }

    /**
     * Extract type information from docblock
     */
    protected function extractFromDocBlock(ReflectionMethod $reflection): array
    {
        $docComment = $reflection->getDocComment();
        if (!$docComment) {
            return [];
        }

        // Extract @return tag
        if (preg_match('/@return\s+([^\s]+)/', $docComment, $matches)) {
            $type = $matches[1];
            return [
                'type' => $this->mapPhpTypeToOpenApi($type),
                'format' => $this->getFormatForType($type),
            ];
        }

        return [];
    }

    /**
     * Analyze a Spatie Data object to extract its structure
     */
    public function analyzeSpatieDataObject(string $dataClass): array
    {
        if (!class_exists($dataClass) || !is_subclass_of($dataClass, Data::class)) {
            return [];
        }

        $reflection = new ReflectionClass($dataClass);
        $properties = $reflection->getProperties();

        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $propertyType = $property->getType();

            if (!$propertyType) {
                continue;
            }

            $typeName = $propertyType->getName();
            $schema['properties'][$propertyName] = [
                'type' => $this->mapPhpTypeToOpenApi($typeName),
                'format' => $this->getFormatForType($typeName),
            ];

            if (!$propertyType->allowsNull()) {
                $schema['required'][] = $propertyName;
            }

            // Handle nested Data objects
            if (class_exists($typeName) && is_subclass_of($typeName, Data::class)) {
                $schema['properties'][$propertyName] = $this->analyzeSpatieDataObject($typeName);
            }
        }

        return $schema;
    }

    /**
     * Map PHP types to OpenAPI types
     */
    protected function mapPhpTypeToOpenApi(string $type): string
    {
        return match ($type) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            'object', 'stdClass' => 'object',
            default => 'string',
        };
    }

    /**
     * Get format for specific types
     */
    protected function getFormatForType(string $type): ?string
    {
        return match ($type) {
            'float', 'double' => 'float',
            'DateTime', '\DateTime' => 'date-time',
            'DateTimeImmutable', '\DateTimeImmutable' => 'date-time',
            default => null,
        };
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

            // Try to get properties from PHPDoc
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

            // If no documentation found, analyze toArray method
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
}
