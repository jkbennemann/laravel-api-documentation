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
use ReflectionType;
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
            
            // Handle union types (e.g., UserData|AdvancedUserData)
            if (strpos($type, '|') !== false) {
                $types = explode('|', $type);
                $schemas = [];
                
                foreach ($types as $singleType) {
                    $singleType = trim($singleType);
                    $resolvedType = $this->resolveClassName($singleType, $reflection->getDeclaringClass());
                    
                    // Check if it's a Spatie Data object
                    if (class_exists($resolvedType) && is_subclass_of($resolvedType, Data::class)) {
                        $schemas[] = $this->analyzeSpatieDataObject($resolvedType);
                    } else {
                        $schemas[] = [
                            'type' => $this->mapPhpTypeToOpenApi($singleType),
                            'format' => $this->getFormatForType($singleType),
                        ];
                    }
                }
                
                return ['oneOf' => $schemas];
            }
            
            // Single type - resolve the class name
            $resolvedType = $this->resolveClassName($type, $reflection->getDeclaringClass());
            
            // Check if it's a Spatie Data object
            if (class_exists($resolvedType) && is_subclass_of($resolvedType, Data::class)) {
                return $this->analyzeSpatieDataObject($resolvedType);
            }
            
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

        return $this->buildSpatieDataSchema($dataClass);
    }

    /**
     * Build comprehensive schema for Spatie Data objects
     */
    private function buildSpatieDataSchema(string $dataClass, array $processedClasses = []): array
    {
        // Prevent infinite recursion
        if (in_array($dataClass, $processedClasses)) {
            return ['type' => 'object', 'description' => 'Circular reference to ' . $dataClass];
        }

        $processedClasses[] = $dataClass;
        $reflection = new ReflectionClass($dataClass);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return ['type' => 'object', 'properties' => []];
        }

        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        // Check for global mapping attributes
        $hasSnakeCaseMapping = $this->usesSnakeCaseMapping($reflection);

        foreach ($constructor->getParameters() as $parameter) {
            $propertyName = $parameter->getName();
            $type = $parameter->getType();
            
            if (!$type) {
                continue;
            }

            // Apply name mapping if present
            $outputName = $this->getOutputPropertyName($reflection, $propertyName, $hasSnakeCaseMapping);
            
            // Determine if property is required (not nullable and no default value)
            $isRequired = !$type->allowsNull() && !$parameter->isDefaultValueAvailable();
            
            if ($isRequired) {
                $schema['required'][] = $outputName;
            }

            // Handle different parameter types
            $propertySchema = $this->buildPropertySchema($type, $processedClasses);
            
            // Add property description from docblock if available
            $propertySchema['description'] = $this->getParameterDescription($constructor, $propertyName);
            
            $schema['properties'][$outputName] = $propertySchema;
        }

        return $schema;
    }

    /**
     * Build schema for individual property types
     */
    private function buildPropertySchema(\ReflectionType $type, array $processedClasses = []): array
    {
        $typeName = $type->getName();

        // Handle union types (PHP 8+)
        if ($type instanceof \ReflectionUnionType) {
            return $this->handleUnionType($type, $processedClasses);
        }

        // Handle nullable types
        $schema = [];
        if ($type->allowsNull()) {
            $schema['nullable'] = true;
        }

        // Handle collections with DataCollectionOf attribute
        if ($this->isCollection($typeName)) {
            return array_merge($schema, $this->handleCollectionType($typeName, $processedClasses));
        }

        // Handle nested Spatie Data objects
        if (class_exists($typeName) && is_subclass_of($typeName, Data::class)) {
            $nestedSchema = $this->buildSpatieDataSchema($typeName, $processedClasses);
            return array_merge($schema, $nestedSchema);
        }

        // Handle primitive types
        return array_merge($schema, [
            'type' => $this->mapPhpTypeToOpenApi($typeName),
            'format' => $this->getFormatForType($typeName),
        ]);
    }

    /**
     * Handle union types in PHP 8+
     */
    private function handleUnionType(\ReflectionUnionType $unionType, array $processedClasses = []): array
    {
        $types = [];
        $hasNull = false;

        foreach ($unionType->getTypes() as $type) {
            if ($type->getName() === 'null') {
                $hasNull = true;
                continue;
            }

            $types[] = $this->buildPropertySchema($type, $processedClasses);
        }

        if (count($types) === 1) {
            $schema = $types[0];
            if ($hasNull) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        // Multiple types - use oneOf
        $schema = ['oneOf' => $types];
        if ($hasNull) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * Handle collection types (arrays, Collections, etc.)
     */
    private function handleCollectionType(string $typeName, array $processedClasses = []): array
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'object'], // Default item type
        ];

        // Try to determine item type from DataCollectionOf attribute or other hints
        // This would need to be enhanced based on the specific context where it's called
        
        return $schema;
    }

    /**
     * Check if a class uses snake_case mapping
     */
    private function usesSnakeCaseMapping(ReflectionClass $reflection): bool
    {
        $mapNameAttributes = $reflection->getAttributes(\Spatie\LaravelData\Attributes\MapName::class);
        $mapOutputAttributes = $reflection->getAttributes(\Spatie\LaravelData\Attributes\MapOutputName::class);
        
        foreach (array_merge($mapNameAttributes, $mapOutputAttributes) as $attribute) {
            // MapName uses constructor parameter, not property
            $args = $attribute->getArguments();
            if (!empty($args) && $args[0] === \Spatie\LaravelData\Mappers\SnakeCaseMapper::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get output property name considering mapping attributes
     */
    private function getOutputPropertyName(ReflectionClass $reflection, string $propertyName, bool $hasSnakeCaseMapping): string
    {
        // Check for property-specific mapping first
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);
            $mapNameAttributes = $property->getAttributes(\Spatie\LaravelData\Attributes\MapName::class);
            
            if (!empty($mapNameAttributes)) {
                $instance = $mapNameAttributes[0]->newInstance();
                return $instance->name ?? $propertyName;
            }
        }

        // Apply global mapping
        if ($hasSnakeCaseMapping) {
            return \Illuminate\Support\Str::snake($propertyName);
        }

        return $propertyName;
    }

    /**
     * Get parameter description from constructor docblock
     */
    private function getParameterDescription(ReflectionMethod $constructor, string $parameterName): string
    {
        $docComment = $constructor->getDocComment();
        if (!$docComment) {
            return '';
        }

        // Extract @param descriptions
        preg_match_all('/@param\s+[^\s]+\s+\$' . preg_quote($parameterName) . '\s+(.*)$/m', $docComment, $matches);
        
        return trim($matches[1][0] ?? '');
    }

    /**
     * Check if type represents a collection
     */
    private function isCollection(string $typeName): bool
    {
        return in_array($typeName, [
            'array',
            \Illuminate\Support\Collection::class,
            \Illuminate\Database\Eloquent\Collection::class,
        ]) || is_subclass_of($typeName, \Illuminate\Support\Collection::class);
    }

    /**
     * Map PHP types to OpenAPI types
     */
    protected function mapPhpTypeToOpenApi(string $type): string
    {
        // Handle Laravel collection types
        if ($this->isCollectionType($type)) {
            return 'array';
        }

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

    /**
     * Check if type represents a Laravel collection type
     */
    private function isCollectionType(string $typeName): bool
    {
        // Handle short class names (e.g., 'ResourceCollection')
        $shortName = class_basename($typeName);
        
        $collectionTypes = [
            'ResourceCollection',
            'JsonResourceCollection',
            'Collection',
            'LengthAwarePaginator',
            'Paginator',
        ];

        if (in_array($shortName, $collectionTypes)) {
            return true;
        }

        // Handle full class names
        $fullClassNames = [
            ResourceCollection::class,
            \Illuminate\Http\Resources\Json\JsonResourceCollection::class,
            \Illuminate\Support\Collection::class,
            \Illuminate\Database\Eloquent\Collection::class,
            \Illuminate\Pagination\LengthAwarePaginator::class,
            \Illuminate\Pagination\Paginator::class,
        ];

        return in_array($typeName, $fullClassNames) || 
               (class_exists($typeName) && is_subclass_of($typeName, ResourceCollection::class));
    }

    /**
     * Resolve class name using the controller's namespace and imports
     */
    private function resolveClassName(string $typeName, ReflectionClass $declaringClass): string
    {
        // Check if the type is already a fully qualified class name
        if (strpos($typeName, '\\') !== false) {
            return $typeName;
        }

        // Try with the controller's namespace first
        $namespace = $declaringClass->getNamespaceName();
        $resolvedType = $namespace . '\\DTOs\\' . $typeName; // Try DTOs subdirectory
        if (class_exists($resolvedType)) {
            return $resolvedType;
        }

        $resolvedType = $namespace . '\\' . $typeName;
        if (class_exists($resolvedType)) {
            return $resolvedType;
        }

        // Parse the source file to find use statements
        $filename = $declaringClass->getFileName();
        if ($filename && file_exists($filename)) {
            $content = file_get_contents($filename);
            if ($content !== false) {
                // Extract use statements
                if (preg_match_all('/use\s+([^;]+);/', $content, $matches)) {
                    foreach ($matches[1] as $useStatement) {
                        $useStatement = trim($useStatement);
                        
                        // Check if this use statement matches our type name
                        if (strpos($useStatement, '\\' . $typeName) !== false || 
                            substr($useStatement, -strlen($typeName)) === $typeName) {
                            if (class_exists($useStatement)) {
                                return $useStatement;
                            }
                        }
                    }
                }
            }
        }

        // Fallback to the original type name
        return $typeName;
    }
}
