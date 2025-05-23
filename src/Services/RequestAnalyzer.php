<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Request;
use Illuminate\Support\Str;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class RequestAnalyzer
{
    private array $ruleTypeMapping;

    private bool $enabled;

    public function __construct(private readonly Repository $configuration)
    {
        $this->enabled = $configuration->get('api-documentation.smart_requests.enabled', true);
        $this->ruleTypeMapping = $configuration->get('api-documentation.smart_requests.rule_types', []);
    }

    /**
     * Analyze a request class to determine its parameter structure
     */
    public function analyzeRequest(?string $requestClass): array
    {
        if (! $this->enabled || ! $requestClass || ! class_exists($requestClass)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($requestClass);

            // 1. First check for Parameter attributes on the class
            $parameters = $this->extractParameterAttributes($reflection);
            if (! empty($parameters)) {
                return $parameters;
            }

            // 2. Then check for validation rules in the class
            if ($reflection->isSubclassOf(FormRequest::class)) {
                // Try rules() method first
                $parameters = $this->analyzeRulesMethod($reflection);
                if (! empty($parameters)) {
                    return $parameters;
                }

                // Then try rules property
                return $this->analyzeValidationRulesProperty($reflection);
            }

            // 3. For regular Request class, try to get rules from validate() method
            if ($requestClass === Request::class || $reflection->isSubclassOf(Request::class)) {
                $method = $reflection->getMethod('validate');
                $parameters = $this->extractValidationRulesFromMethod($method);
                if (! empty($parameters)) {
                    return $parameters;
                }
            }

            // 4. Check if it's a Spatie Data request class
            if (class_exists($requestClass) && is_subclass_of($requestClass, \Spatie\LaravelData\Data::class)) {
                return $this->analyzeSpatieDataRequest($requestClass);
            }

            return [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Extract Parameter attributes from class
     */
    private function extractParameterAttributes(ReflectionClass $reflection): array
    {
        $parameters = [];
        
        // First check for attributes on the class itself
        $attributes = $reflection->getAttributes(Parameter::class);
        foreach ($attributes as $attribute) {
            $parameter = $attribute->newInstance();
            $parameters[$parameter->name] = [
                'type' => $this->mapPhpTypeToOpenApi($parameter->type),
                'description' => $parameter->description,
                'required' => $parameter->required,
            ];

            if ($parameter->format) {
                $parameters[$parameter->name]['format'] = $parameter->format;
            }

            if ($parameter->deprecated) {
                $parameters[$parameter->name]['deprecated'] = true;
            }

            if ($parameter->example !== null) {
                $parameters[$parameter->name]['example'] = $parameter->example;
            }
        }
        
        // Then check for attributes on the rules() method if it exists
        if ($reflection->hasMethod('rules')) {
            $rulesMethod = $reflection->getMethod('rules');
            $methodAttributes = $rulesMethod->getAttributes(Parameter::class);
            
            foreach ($methodAttributes as $attribute) {
                $parameter = $attribute->newInstance();
                $parameters[$parameter->name] = [
                    'type' => $this->mapPhpTypeToOpenApi($parameter->type),
                    'description' => $parameter->description,
                    'required' => $parameter->required,
                ];

                if ($parameter->format) {
                    $parameters[$parameter->name]['format'] = $parameter->format;
                }

                if ($parameter->deprecated) {
                    $parameters[$parameter->name]['deprecated'] = true;
                }

                if ($parameter->example !== null) {
                    $parameters[$parameter->name]['example'] = $parameter->example;
                }
            }
        }

        return $parameters;
    }

    /**
     * Analyze rules() method using PHP-Parser
     */
    private function analyzeRulesMethod(ReflectionClass $reflection): array
    {
        try {
            $fileName = $reflection->getFileName();
            if (! $fileName || ! file_exists($fileName)) {
                return [];
            }

            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse(file_get_contents($fileName));

            $nodeFinder = new NodeFinder;
            $parameters = [];

            // Find the rules method
            $rulesMethod = $nodeFinder->findFirst($ast, function ($node) {
                return $node instanceof \PhpParser\Node\Stmt\ClassMethod
                    && $node->name->toString() === 'rules';
            });

            if (! $rulesMethod) {
                return $this->analyzeValidationRulesProperty($reflection);
            }

            // Find return statement in the method
            $returnNode = $nodeFinder->findFirst($rulesMethod, function ($node) {
                return $node instanceof \PhpParser\Node\Stmt\Return_;
            });

            if (! $returnNode || ! $returnNode->expr instanceof Array_) {
                return [];
            }

            // Analyze array items
            foreach ($returnNode->expr->items as $item) {
                if (! $item || ! $item->key instanceof String_) {
                    continue;
                }

                $fieldName = $item->key->value;
                $rules = $this->extractRules($item->value);

                $parameters[$fieldName] = $this->parseValidationRules($rules);
            }

            // Transform flat nested parameters into proper nested structure
            return $this->transformNestedParameters($parameters);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Extract rules from node value
     */
    private function extractRules($value): array
    {
        if ($value instanceof String_) {
            return explode('|', $value->value);
        }

        if ($value instanceof Array_) {
            $rules = [];
            foreach ($value->items as $item) {
                if ($item->value instanceof String_) {
                    $rules[] = $item->value->value;
                }
            }

            return $rules;
        }

        return [];
    }

    /**
     * Parse Laravel validation rules into OpenAPI schema
     */
    private function parseValidationRules(array $rules): array
    {
        $parameter = [
            'type' => 'string',
            'required' => false,
        ];

        foreach ($rules as $rule) {
            $rule = is_string($rule) ? $rule : '';

            // Check required
            if ($rule === 'required') {
                $parameter['required'] = true;

                continue;
            }

            // Check nullable
            if ($rule === 'nullable') {
                $parameter['nullable'] = true;

                continue;
            }

            // Check type
            if (isset($this->ruleTypeMapping[$rule])) {
                $parameter = array_merge($parameter, $this->ruleTypeMapping[$rule]);

                continue;
            }

            // Handle specific rules
            if (Str::startsWith($rule, 'max:')) {
                $parameter['maxLength'] = (int) substr($rule, 4);
            } elseif (Str::startsWith($rule, 'min:')) {
                $parameter['minLength'] = (int) substr($rule, 4);
            } elseif ($rule === 'email') {
                $parameter['format'] = 'email';
            } elseif ($rule === 'url') {
                $parameter['format'] = 'uri';
            } elseif ($rule === 'date') {
                $parameter['type'] = 'string';
                $parameter['format'] = 'date';
            } elseif ($rule === 'datetime') {
                $parameter['type'] = 'string';
                $parameter['format'] = 'date-time';
            } elseif ($rule === 'numeric') {
                $parameter['type'] = 'number';
            } elseif ($rule === 'integer') {
                $parameter['type'] = 'integer';
            } elseif ($rule === 'boolean') {
                $parameter['type'] = 'boolean';
            } elseif (Str::startsWith($rule, 'in:')) {
                $values = explode(',', substr($rule, 3));
                $parameter['enum'] = $values;
            }
        }

        return $parameter;
    }

    /**
     * Analyze rules property if rules method doesn't exist
     */
    private function analyzeValidationRulesProperty(ReflectionClass $reflection): array
    {
        try {
            if (! $reflection->hasProperty('rules')) {
                return [];
            }

            $rulesProperty = $reflection->getProperty('rules');
            $rulesProperty->setAccessible(true);

            $request = $reflection->newInstance();
            $rules = $rulesProperty->getValue($request);

            if (! is_array($rules)) {
                return [];
            }

            $parameters = [];
            foreach ($rules as $field => $fieldRules) {
                $rules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
                $parameters[$field] = $this->parseValidationRules($rules);
            }

            return $parameters;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Extract validation rules from validate() method
     */
    private function extractValidationRulesFromMethod(ReflectionMethod $method): array
    {
        try {
            $fileName = $method->getFileName();
            if (! $fileName || ! file_exists($fileName)) {
                return [];
            }

            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse(file_get_contents($fileName));

            $nodeFinder = new NodeFinder;
            $methodNode = $nodeFinder->findFirst($ast, function ($node) use ($method) {
                return $node instanceof \PhpParser\Node\Stmt\ClassMethod
                    && $node->name->toString() === $method->getName();
            });

            if (! $methodNode) {
                return [];
            }

            // Find validate() call
            $validateCall = $nodeFinder->findFirst($methodNode, function ($node) {
                return $node instanceof \PhpParser\Node\Expr\MethodCall
                    && $node->name->toString() === 'validate'
                    && isset($node->args[0]);
            });

            if (! $validateCall || ! $validateCall->args[0]->value instanceof Array_) {
                return [];
            }

            $parameters = [];
            foreach ($validateCall->args[0]->value->items as $item) {
                if (! $item || ! $item->key instanceof String_) {
                    continue;
                }

                $fieldName = $item->key->value;
                $rules = $this->extractRules($item->value);

                $parameters[$fieldName] = $this->parseValidationRules($rules);
            }

            return $parameters;
        } catch (Throwable) {
            return [];
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
     * Analyze Spatie Data request class for parameter structure
     */
    public function analyzeSpatieDataRequest(string $dataClass): array
    {
        if (!class_exists($dataClass) || !is_subclass_of($dataClass, \Spatie\LaravelData\Data::class)) {
            return [];
        }

        return $this->buildSpatieDataRequestSchema($dataClass);
    }

    /**
     * Build parameter schema for Spatie Data request objects
     */
    private function buildSpatieDataRequestSchema(string $dataClass, array $processedClasses = [], string $prefix = ''): array
    {
        // Prevent infinite recursion
        if (in_array($dataClass, $processedClasses)) {
            return [];
        }

        $processedClasses[] = $dataClass;
        $reflection = new \ReflectionClass($dataClass);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return [];
        }

        $parameters = [];

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
            $fullName = $prefix ? $prefix . '.' . $outputName : $outputName;
            
            // Determine if property is required
            $isRequired = !$type->allowsNull() && !$parameter->isDefaultValueAvailable();
            
            // Handle different parameter types
            $parameterSchema = $this->buildRequestParameterSchema($type, $processedClasses, $fullName);
            
            if (!empty($parameterSchema)) {
                if (isset($parameterSchema['parameters'])) {
                    // Nested parameters - merge them
                    $parameters = array_merge($parameters, $parameterSchema['parameters']);
                } else {
                    // Single parameter
                    $parameterSchema['required'] = $isRequired;
                    $parameterSchema['description'] = $this->getParameterDescription($constructor, $propertyName);
                    $parameters[$fullName] = $parameterSchema;
                }
            }
        }

        return $parameters;
    }

    /**
     * Build request parameter schema for individual property types
     */
    private function buildRequestParameterSchema(\ReflectionType $type, array $processedClasses = [], string $fullName = ''): array
    {
        $typeName = $type->getName();

        // Handle union types (PHP 8+)
        if ($type instanceof \ReflectionUnionType) {
            return $this->handleRequestUnionType($type, $processedClasses, $fullName);
        }

        // Handle collections
        if ($this->isCollection($typeName)) {
            return [
                'type' => 'array',
                'format' => null,
            ];
        }

        // Handle nested Spatie Data objects
        if (class_exists($typeName) && is_subclass_of($typeName, \Spatie\LaravelData\Data::class)) {
            $nestedParameters = $this->buildSpatieDataRequestSchema($typeName, $processedClasses, $fullName);
            return ['parameters' => $nestedParameters];
        }

        // Handle primitive types
        return [
            'type' => $this->mapPhpTypeToOpenApi($typeName),
            'format' => $this->getFormatForType($typeName),
        ];
    }

    /**
     * Handle union types for request parameters
     */
    private function handleRequestUnionType(\ReflectionUnionType $unionType, array $processedClasses = [], string $fullName = ''): array
    {
        $types = [];
        
        foreach ($unionType->getTypes() as $type) {
            if ($type->getName() === 'null') {
                continue;
            }

            $schema = $this->buildRequestParameterSchema($type, $processedClasses, $fullName);
            if (!empty($schema) && !isset($schema['parameters'])) {
                $types[] = $schema['type'];
            }
        }

        if (count($types) === 1) {
            return [
                'type' => $types[0],
                'format' => null,
            ];
        }

        // Multiple types - use string as fallback
        return [
            'type' => 'string',
            'format' => null,
        ];
    }

    /**
     * Check if a class uses snake_case mapping
     */
    private function usesSnakeCaseMapping(ReflectionClass $reflection): bool
    {
        $mapNameAttributes = $reflection->getAttributes(\Spatie\LaravelData\Attributes\MapName::class);
        $mapOutputAttributes = $reflection->getAttributes(\Spatie\LaravelData\Attributes\MapOutputName::class);
        
        foreach (array_merge($mapNameAttributes, $mapOutputAttributes) as $attribute) {
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
    private function getOutputPropertyName(\ReflectionClass $reflection, string $propertyName, bool $hasSnakeCaseMapping): string
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
    private function getParameterDescription(\ReflectionMethod $constructor, string $parameterName): string
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
     * Get format for specific types
     */
    private function getFormatForType(string $typeName): ?string
    {
        return match ($typeName) {
            'DateTime', 'DateTimeImmutable', 'Carbon', \Carbon\Carbon::class => 'date-time',
            'int', 'integer' => 'int32',
            'float', 'double' => 'float',
            default => null,
        };
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
     * Transform flat nested parameters into proper nested structure
     */
    private function transformNestedParameters(array $parameters): array
    {
        $transformedParameters = [];
        $nestedGroups = [];

        foreach ($parameters as $key => $value) {
            if (strpos($key, '.') !== false) {
                $parts = explode('.', $key, 2); // Split into max 2 parts: parent and child
                $parentKey = $parts[0];
                $childKey = $parts[1];

                // Initialize the parent parameter if it doesn't exist
                if (!isset($nestedGroups[$parentKey])) {
                    $nestedGroups[$parentKey] = [
                        'name' => $parentKey,
                        'description' => null,
                        'type' => 'array',
                        'format' => null,
                        'required' => false,
                        'deprecated' => false,
                        'parameters' => [],
                    ];
                }

                // Add the child parameter to the parent's parameters
                $nestedGroups[$parentKey]['parameters'][$childKey] = [
                    'name' => $childKey,
                    'description' => $value['description'] ?? null,
                    'type' => $value['type'] ?? 'string',
                    'format' => $value['format'] ?? null,
                    'required' => $value['required'] ?? false,
                    'deprecated' => $value['deprecated'] ?? false,
                    'parameters' => $value['parameters'] ?? [],
                ];
            } else {
                // Check if this key is a parent of nested parameters
                $hasNestedChildren = false;
                foreach (array_keys($parameters) as $otherKey) {
                    if (strpos($otherKey, $key . '.') === 0) {
                        $hasNestedChildren = true;
                        break;
                    }
                }

                if ($hasNestedChildren) {
                    // This will be handled when processing the nested children
                    if (!isset($nestedGroups[$key])) {
                        $nestedGroups[$key] = [
                            'name' => $key,
                            'description' => $value['description'] ?? null,
                            'type' => 'array',
                            'format' => $value['format'] ?? null,
                            'required' => $value['required'] ?? false,
                            'deprecated' => $value['deprecated'] ?? false,
                            'parameters' => [],
                        ];
                    }
                } else {
                    // Regular non-nested parameter
                    $transformedParameters[$key] = $value;
                }
            }
        }

        // Merge nested groups into the result
        return array_merge($transformedParameters, $nestedGroups);
    }
}
