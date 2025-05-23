<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Contracts\Config\Repository;
use openapiphp\openapi\spec\Schema;
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

        // Handle union types (PHP 8+) FIRST before calling getName()
        if (method_exists($returnType, 'getTypes')) {
            return $this->handleUnionTypes($returnType->getTypes());
        }

        $typeName = $returnType->getName();

        // Check if it's a Spatie Data object
        if (class_exists($typeName) && is_subclass_of($typeName, Data::class)) {
            return $this->analyzeSpatieDataObject($typeName);
        }

        // Check if it's a ResourceCollection - these should be arrays
        if (class_exists($typeName) && is_a($typeName, \Illuminate\Http\Resources\Json\ResourceCollection::class, true)) {
            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                ],
            ];
        }

        // Check if it's a JsonResource and analyze its toArray method (but not ResourceCollection)
        if (class_exists($typeName) && is_subclass_of($typeName, JsonResource::class)) {
            return $this->analyzeJsonResourceResponse($typeName);
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
            
            // Check if it's a ResourceCollection - these should be arrays
            if (class_exists($resolvedType) && is_a($resolvedType, \Illuminate\Http\Resources\Json\ResourceCollection::class, true)) {
                return [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                    ],
                ];
            }
            
            // Check if it's a JsonResource and analyze its toArray method
            if (class_exists($resolvedType) && is_subclass_of($resolvedType, JsonResource::class)) {
                return $this->analyzeJsonResourceResponse($resolvedType);
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

            // Get the corresponding property for attribute checking
            $property = null;
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
            }

            // Handle different parameter types
            $propertySchema = $this->buildPropertySchema($type, $processedClasses, $parameter, $property);
            
            // Add property description from docblock if available
            $propertySchema['description'] = $this->getParameterDescription($constructor, $propertyName);
            
            $schema['properties'][$outputName] = $propertySchema;
        }

        return $schema;
    }

    /**
     * Build schema for individual property types
     */
    private function buildPropertySchema(\ReflectionType $type, array $processedClasses = [], ?\ReflectionParameter $parameter = null, ?\ReflectionProperty $property = null): array
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
            $collectionItemType = null;
            
            // Extract DataCollectionOf attribute from property
            if ($property) {
                $collectionOfAttributes = $property->getAttributes(\Spatie\LaravelData\Attributes\DataCollectionOf::class);
                
                if (!empty($collectionOfAttributes)) {
                    $instance = $collectionOfAttributes[0]->newInstance();
                    $collectionItemType = $instance->class;
                }
            }
            
            return array_merge($schema, $this->handleCollectionType($typeName, $processedClasses, $collectionItemType));
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
    private function handleCollectionType(string $typeName, array $processedClasses = [], ?string $collectionItemType = null): array
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'object'], // Default item type
        ];

        // Try to determine item type from DataCollectionOf attribute or other hints
        if ($collectionItemType) {
            // Check if the collection item type is a Spatie Data class
            if (class_exists($collectionItemType) && is_subclass_of($collectionItemType, Data::class)) {
                $schema['items'] = $this->buildSpatieDataSchema($collectionItemType, $processedClasses);
            } else {
                // Handle primitive types
                $schema['items'] = [
                    'type' => $this->mapPhpTypeToOpenApi($collectionItemType),
                    'format' => $this->getFormatForType($collectionItemType),
                ];
            }
        }
        
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
    public function analyzeDataResponse(string $dataClass): array
    {
        // Rule: Project Context - Response classes can be enhanced with PHP annotations
        try {
            $reflection = new ReflectionClass($dataClass);
            $properties = [];
            $parameterAttributes = [];
            
            // Extract Parameter attributes from the class
            $classAttributes = $reflection->getAttributes();
            foreach ($classAttributes as $attribute) {
                // Use both fully qualified and non-qualified class names for compatibility
                if ($attribute->getName() === '\\JkBennemann\\LaravelApiDocumentation\\Attributes\\Parameter' || 
                    $attribute->getName() === 'JkBennemann\\LaravelApiDocumentation\\Attributes\\Parameter') {
                    $args = $attribute->getArguments();
                    $paramName = $args['name'] ?? null;
                    if ($paramName) {
                        $parameterAttributes[$paramName] = $args;
                    }
                }
            }
            
            // Process properties
            foreach ($reflection->getProperties() as $property) {
                $type = $property->getType();
                $propName = $property->getName();
                $snakeCaseName = $this->usesSnakeCaseMapping($reflection) ? 
                    $this->getOutputPropertyName($reflection, $propName, true) : 
                    $propName;
                
                // Start with basic property info
                $properties[$snakeCaseName] = [
                    'type' => $this->mapPhpTypeToOpenApi($type?->getName() ?? 'mixed'),
                    'description' => $this->extractPropertyDescription($property),
                ];
            }
            
            // Apply Parameter attributes to properties
            foreach ($parameterAttributes as $paramName => $args) {
                if (!isset($properties[$paramName])) {
                    // Create property if it doesn't exist yet
                    $properties[$paramName] = [
                        'type' => $args['type'] ?? 'string',
                        'description' => $args['description'] ?? '',
                    ];
                }
                
                // Add format if available
                if (isset($args['format'])) {
                    $properties[$paramName]['format'] = $args['format'];
                }
                
                // Add example if available
                if (isset($args['example'])) {
                    $properties[$paramName]['example'] = $args['example'];
                }
                
                // Override description if available
                if (isset($args['description'])) {
                    $properties[$paramName]['description'] = $args['description'];
                }
                
                // Override type if available
                if (isset($args['type'])) {
                    $properties[$paramName]['type'] = $args['type'];
                }
            }
            
            // Generate an example from the properties
            $example = $this->generateExampleFromProperties($properties);
            
            return [
                'type' => 'object',
                'properties' => $properties,
                'example' => $example,
                'enhanced_analysis' => true,
            ];
        } catch (Throwable $e) {
            // For debugging: report($e);
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
     * Analyze a JsonResource to determine its structure
     */
    private function analyzeJsonResourceResponse(string $resourceClass): array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            $toArrayMethod = $reflection->getMethod('toArray');
            $returnType = $toArrayMethod->getReturnType();

            // Extract properties from the resource class
            $properties = $this->extractResourceProperties($resourceClass);

            if (! empty($properties)) {
                // Generate an example based on the properties
                $example = $this->generateExampleFromProperties($properties);
                
                // Determine if this is an array response based on the method body
                $methodBody = $this->getMethodBody($reflection, 'toArray');
                $isArrayResponse = strpos($methodBody, 'array_map') !== false;
                
                if ($isArrayResponse) {
                    return [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => is_array($properties['properties'] ?? null) ? $properties['properties'] : $properties,
                        ],
                        'example' => $example,
                        'enhanced_analysis' => true,
                    ];
                }
                
                // Add the example to the response
                $properties['example'] = $example;
                $properties['enhanced_analysis'] = true;
                
                return $properties;
            }

            // Fallback if no properties found
            return [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'description' => 'Resource data',
                    ],
                ],
                'example' => ['data' => []],
                'enhanced_analysis' => true,
            ];
        } catch (Throwable $e) {
            return [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'description' => 'Resource data',
                    ],
                ],
                'example' => ['data' => []],
                'enhanced_analysis' => true,
            ];
        }
    }
    
    /**
     * Get the method body from a reflection class
     */
    private function getMethodBody(ReflectionClass $reflection, string $methodName): string
    {
        try {
            $method = $reflection->getMethod($methodName);
            $filename = $reflection->getFileName();
            
            if (!$filename || !file_exists($filename)) {
                return '';
            }
            
            $fileContent = file_get_contents($filename);
            $lines = explode("\n", $fileContent);
            
            $startLine = $method->getStartLine() - 1;
            $endLine = $method->getEndLine() - 1;
            return implode("\n", array_slice($lines, $startLine, $endLine - $startLine + 1));
        } catch (Throwable) {
            return '';
        }
    }
    
    /**
     * Generate an example from the properties structure
     */
    private function generateExampleFromProperties(array $properties): array
    {
        // Check if this is an array response
        if (isset($properties['type']) && $properties['type'] === 'array' && isset($properties['items']['properties'])) {
            return [$this->generateExampleObject($properties['items']['properties'])];
        }
        
        // Check if properties are nested under 'properties' key
        if (isset($properties['properties']) && is_array($properties['properties'])) {
            return $this->generateExampleObject($properties['properties']);
        }
        
        // Direct properties
        return $this->generateExampleObject($properties);
    }
    
    /**
     * Generate an example object from properties
     */
    private function generateExampleObject(array $properties): array
    {
        // Rule: Project Context - OpenAPI example is generated automatically
        $example = [];
        
        foreach ($properties as $key => $property) {
            // If an example is explicitly provided in the property, use it
            if (isset($property['example'])) {
                $example[$key] = $property['example'];
                continue;
            }
            
            $type = $property['type'] ?? 'string';
            
            switch ($type) {
                case 'integer':
                    $example[$key] = 1;
                    break;
                case 'number':
                    $example[$key] = 1.0;
                    break;
                case 'boolean':
                    $example[$key] = true;
                    break;
                case 'array':
                    if (isset($property['items']['properties'])) {
                        $example[$key] = [$this->generateExampleObject($property['items']['properties'])];
                    } elseif (isset($property['example']) && is_array($property['example'])) {
                        // Use the provided example array if available
                        $example[$key] = $property['example'];
                    } else {
                        $example[$key] = ['example'];
                    }
                    break;
                case 'object':
                    if (isset($property['properties'])) {
                        $example[$key] = $this->generateExampleObject($property['properties']);
                    } else {
                        $example[$key] = new \stdClass();
                    }
                    break;
                default:
                    // Handle special property names
                    if ($key === 'id' || $key === 'hashId' || str_ends_with($key, '_id')) {
                        $example[$key] = 'abc123';
                    } elseif ($key === 'type') {
                        $example[$key] = 'product';
                    } elseif ($key === 'title') {
                        $example[$key] = 'Example Title';
                    } elseif ($key === 'email') {
                        $example[$key] = 'user@example.com';
                    } else {
                        $example[$key] = 'Example ' . ucfirst($key);
                    }
            }
        }
        
        return $example;
    }

    /**
     * Extract properties from a Resource class
     */
    private function extractResourceProperties(string $resourceClass): array
    {
        try {
            $reflection = new ReflectionClass($resourceClass);
            
            // First try to analyze the toArray method body
            if ($reflection->hasMethod('toArray')) {
                $methodProperties = $this->analyzeToArrayMethodBody($reflection);
                if (!empty($methodProperties)) {
                    return $methodProperties;
                }
            }
            
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
            }

            return $properties;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Analyze the toArray method body to extract dynamic response structure
     */
    private function analyzeToArrayMethodBody(ReflectionClass $reflection): array
    {
        try {
            $method = $reflection->getMethod('toArray');
            $filename = $reflection->getFileName();
            
            if (!$filename || !file_exists($filename)) {
                return [];
            }
            
            $fileContent = file_get_contents($filename);
            $lines = explode("\n", $fileContent);
            
            $startLine = $method->getStartLine() - 1;
            $endLine = $method->getEndLine() - 1;
            $methodBody = implode("\n", array_slice($lines, $startLine, $endLine - $startLine + 1));
            
            // Extract array structures from method body
            return $this->extractArrayStructureFromMethodBody($methodBody);
            
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Extract array structure from method body text
     */
    private function extractArrayStructureFromMethodBody(string $methodBody): array
    {
        $properties = [];
        
        // Remove extra whitespace and line breaks for better pattern matching
        $cleanedBody = preg_replace('/\s+/', ' ', $methodBody);
        
        // Enhanced pattern for array_map with inline array definition (handles multi-line and various formats)
        // Matches both static fn and function styles
        if (preg_match('/array_map\s*\(\s*(static\s+fn|function)\s*\(\$?[^)]*\)\s*=>\s*\[([^\]]+)\]/', $cleanedBody, $matches)) {
            $arrayContent = $matches[2];
            $properties = $this->parseInlineArrayStructure($arrayContent);
        }
        // Try alternative pattern for array_map with different formatting
        elseif (preg_match('/array_map\s*\(\s*([^,]+),\s*([^\)]+)\)/', $cleanedBody, $matches)) {
            // Check if the first parameter is a closure with array return
            $closure = $matches[1];
            if (preg_match('/\[([^\]]+)\]/', $closure, $arrayMatches)) {
                $arrayContent = $arrayMatches[1];
                $properties = $this->parseInlineArrayStructure($arrayContent);
            }
        }
        // Pattern for direct array return
        // Matches: return ['key' => $value, 'key2' => $value2];
        elseif (preg_match('/return\s*\[([^\]]+)\]/', $cleanedBody, $matches)) {
            $arrayContent = $matches[1];
            $properties = $this->parseInlineArrayStructure($arrayContent);
        }
        
        // If array_map is detected, wrap in array type
        if (strpos($cleanedBody, 'array_map') !== false) {
            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $properties,
                ],
            ];
        }
        
        return [
            'type' => 'object',
            'properties' => $properties
        ];
    }

    /**
     * Parse inline array structure like ['id' => $value, 'type' => $value2]
     */
    private function parseInlineArrayStructure(string $arrayContent): array
    {
        $properties = [];
        
        // Split by comma but handle nested structures
        $pairs = $this->splitArrayPairs($arrayContent);
        
        foreach ($pairs as $pair) {
            if (preg_match('/[\'"]([^\'\"]+)[\'"]\s*=>\s*(.+)/', trim($pair), $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                
                // Determine type based on the value pattern
                $type = $this->determineTypeFromValue($value);
                
                $properties[$key] = [
                    'type' => $type,
                    'description' => "The {$key} field",
                ];
            }
        }
        
        return $properties;
    }

    /**
     * Split array pairs handling nested structures
     */
    private function splitArrayPairs(string $content): array
    {
        $pairs = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = null;
        
        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];
            
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && ($i === 0 || $content[$i-1] !== '\\')) {
                $inString = false;
                $stringChar = null;
            }
            
            if (!$inString) {
                if ($char === '[' || $char === '(') {
                    $depth++;
                } elseif ($char === ']' || $char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $pairs[] = trim($current);
                    $current = '';
                    continue;
                }
            }
            
            $current .= $char;
        }
        
        if (!empty(trim($current))) {
            $pairs[] = trim($current);
        }
        
        return $pairs;
    }

    /**
     * Determine OpenAPI type from PHP value pattern
     */
    private function determineTypeFromValue(string $value): string
    {
        // Remove whitespace
        $value = trim($value);
        
        // Check for method calls that might indicate type
        if (preg_match('/->value$/', $value)) {
            return 'string'; // Likely an enum value
        }
        
        // Enhanced HashId detection - handles both direct and nested property access
        if (preg_match('/->hashId$/', $value) || 
            preg_match('/->hashId->hashId$/', $value) || 
            preg_match('/\$entity->hashId/', $value)) {
            return 'string'; // Hash ID
        }
        
        // Enhanced enum type detection
        if (preg_match('/->type$/', $value) || 
            preg_match('/\$entity->type/', $value) || 
            preg_match('/->type->value$/', $value)) {
            return 'string'; // Enum type
        }
        
        if (preg_match('/->id$/', $value)) {
            return 'integer'; // Likely ID field
        }
        
        if (preg_match('/->count\(\)$/', $value)) {
            return 'integer'; // Count method
        }
        
        if (preg_match('/\$[^->]+->(\w+)$/', $value, $matches)) {
            $propertyName = $matches[1];
            
            // Common property name patterns
            if (in_array($propertyName, ['id', 'count', 'total', 'amount'])) {
                return 'integer';
            }
            
            if (in_array($propertyName, ['active', 'enabled', 'visible', 'published'])) {
                return 'boolean';
            }
            
            if (in_array($propertyName, ['title', 'name', 'description', 'email'])) {
                return 'string';
            }
        }
        
        // Default to string for unknown patterns
        return 'string';
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
     * Analyze response from a reflection method for smart features
     */
    public function analyzeResponse(\ReflectionMethod $method): ?array
    {
        $controller = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();
        
        $analysis = $this->analyzeControllerMethod($controller, $methodName);
        
        return !empty($analysis) ? new Schema($analysis) : null;
    }

    /**
     * Analyze method response for smart features (alias for analyzeResponse)
     */
    public function analyzeMethodResponse(string $controller, string $method): array
    {
        return $this->analyzeControllerMethod($controller, $method);
    }

    /**
     * Analyze error responses from a reflection method
     */
    public function analyzeErrorResponses(\ReflectionMethod $method): array
    {
        // For now, return common error responses
        // This could be enhanced to analyze @throws annotations or method body
        return [
            '400' => new Schema(['type' => 'object', 'properties' => [
                'message' => ['type' => 'string'],
                'errors' => ['type' => 'object']
            ]]),
            '401' => new Schema(['type' => 'object', 'properties' => [
                'message' => ['type' => 'string']
            ]]),
            '403' => new Schema(['type' => 'object', 'properties' => [
                'message' => ['type' => 'string']
            ]]),
            '404' => new Schema(['type' => 'object', 'properties' => [
                'message' => ['type' => 'string']
            ]]),
            '500' => new Schema(['type' => 'object', 'properties' => [
                'message' => ['type' => 'string']
            ]])
        ];
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

    /**
     * Extract DataCollectionOf attribute from a property
     */
    private function extractDataCollectionOfType(\ReflectionProperty $property): ?string
    {
        $collectionOfAttributes = $property->getAttributes(\Spatie\LaravelData\Attributes\DataCollectionOf::class);
        
        if (!empty($collectionOfAttributes)) {
            $instance = $collectionOfAttributes[0]->newInstance();
            return $instance->class;
        }
        
        return null;
    }
}
