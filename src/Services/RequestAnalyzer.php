<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Request;
use Illuminate\Support\Str;
use JkBennemann\LaravelApiDocumentation\Attributes\IgnoreDataParameter;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
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
                // Check for parameters merged from route values in prepareForValidation
                $routeParameters = $this->detectRouteParameters($reflection);
                
                // Check for parameters to ignore via IgnoreDataParameter attribute
                $ignoredParameters = $this->detectIgnoredParameters($reflection);
                
                // Try rules() method first
                $parameters = $this->analyzeRulesMethod($reflection);
                
                // Remove route parameters and ignored parameters from the body parameters
                foreach ($routeParameters as $param) {
                    unset($parameters[$param]);
                }
                
                foreach ($ignoredParameters as $param) {
                    unset($parameters[$param]);
                }
                
                if (! empty($parameters)) {
                    return $parameters;
                }

                // Then try rules property
                $parameters = $this->analyzeValidationRulesProperty($reflection);
                
                // Remove route parameters and ignored parameters from the body parameters
                foreach ($routeParameters as $param) {
                    unset($parameters[$param]);
                }
                
                foreach ($ignoredParameters as $param) {
                    unset($parameters[$param]);
                }
                
                return $parameters;
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
        if ($value instanceof \PhpParser\Node\Expr\Array_) {
            $rules = [];
            foreach ($value->items as $ruleItem) {
                if (! $ruleItem || ! $ruleItem->value) {
                    continue;
                }

                if ($ruleItem->value instanceof String_) {
                    $rules[] = $ruleItem->value->value;
                } elseif ($ruleItem->value instanceof \PhpParser\Node\Expr\ClassConstFetch) {
                    // Handle class constants like Rule::in([...])
                    $rules[] = 'in'; // Simplified handling
                }
            }
            return $rules;
        } elseif ($value instanceof String_) {
            // Handle string rules like 'required|string|max:255'
            return explode('|', $value->value);
        }

        return [];
    }

    /**
     * Parse Laravel validation rules into OpenAPI schema
     */
    private function parseValidationRules(array $rules): array
    {
        $type = 'string'; // Default type
        $format = null;
        $required = false;
        $description = null;
        $deprecated = false;

        foreach ($rules as $rule) {
            $rule = trim($rule);
            
            if ($rule === 'required') {
                $required = true;
                continue;
            }
            
            if ($rule === 'deprecated') {
                $deprecated = true;
                continue;
            }

            // Check for type rules
            if (in_array($rule, ['integer', 'numeric', 'int'])) {
                $type = 'integer';
            } elseif (in_array($rule, ['boolean', 'bool'])) {
                $type = 'boolean';
            } elseif (in_array($rule, ['array'])) {
                $type = 'array';
            } elseif (in_array($rule, ['file', 'image'])) {
                $type = 'file';
            } elseif ($rule === 'date') {
                $type = 'string';
                $format = 'date';
            } elseif ($rule === 'email') {
                $type = 'string';
                $format = 'email';
            } elseif ($rule === 'uuid') {
                $type = 'string';
                $format = 'uuid';
            } elseif ($rule === 'url') {
                $type = 'string';
                $format = 'uri';
            } elseif ($rule === 'ip') {
                $type = 'string';
                $format = 'ipv4';
            } elseif ($rule === 'json') {
                $type = 'object';
            }

            // Check for custom mappings from configuration
            foreach ($this->ruleTypeMapping as $rulePattern => $mappedType) {
                if (preg_match('/^' . $rulePattern . '/', $rule)) {
                    $type = $mappedType;
                    break;
                }
            }
        }

        $result = [
            'type' => $type,
            'required' => $required,
        ];

        if ($format) {
            $result['format'] = $format;
        }

        if ($description) {
            $result['description'] = $description;
        }

        if ($deprecated) {
            $result['deprecated'] = true;
        }

        return $result;
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
            
            // Create an instance to get the rules
            $instance = $reflection->newInstanceWithoutConstructor();
            $rules = $rulesProperty->getValue($instance);
            
            if (! is_array($rules)) {
                return [];
            }
            
            $parameters = [];
            foreach ($rules as $field => $fieldRules) {
                if (is_string($fieldRules)) {
                    $fieldRules = explode('|', $fieldRules);
                } elseif (is_array($fieldRules)) {
                    // Already an array of rules
                } else {
                    continue; // Skip if not string or array
                }
                
                $parameters[$field] = $this->parseValidationRules($fieldRules);
            }
            
            return $this->transformNestedParameters($parameters);
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

        try {
            $reflection = new ReflectionClass($dataClass);
            $constructor = $reflection->getConstructor();
            if (!$constructor) {
                return [];
            }

            $parameters = [];
            $hasSnakeCaseMapping = $this->usesSnakeCaseMapping($reflection);

            foreach ($constructor->getParameters() as $parameter) {
                $parameterName = $parameter->getName();
                $outputName = $this->getOutputPropertyName($reflection, $parameterName, $hasSnakeCaseMapping);
                $fullName = $prefix ? "{$prefix}.{$outputName}" : $outputName;

                $parameterType = $parameter->getType();
                if (!$parameterType) {
                    continue;
                }

                // Handle union types
                if ($parameterType instanceof \ReflectionUnionType) {
                    $parameters[$fullName] = $this->handleRequestUnionType($parameterType, $processedClasses, $fullName);
                    continue;
                }

                $typeName = $parameterType->getName();
                
                // Handle nested Spatie Data objects
                if (is_subclass_of($typeName, \Spatie\LaravelData\Data::class)) {
                    $nestedParams = $this->buildSpatieDataRequestSchema($typeName, $processedClasses, $fullName);
                    $parameters = array_merge($parameters, $nestedParams);
                    continue;
                }

                // Handle collections
                if ($this->isCollection($typeName)) {
                    $parameters[$fullName] = [
                        'type' => 'array',
                        'format' => null,
                        'description' => $this->getParameterDescription($constructor, $parameterName),
                        'required' => !$parameter->isOptional(),
                    ];
                    continue;
                }

                // Handle basic types
                $parameters[$fullName] = $this->buildRequestParameterSchema($parameterType, $processedClasses, $fullName);
                $parameters[$fullName]['description'] = $this->getParameterDescription($constructor, $parameterName);
                $parameters[$fullName]['required'] = !$parameter->isOptional();
            }

            return $parameters;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Build request parameter schema for individual property types
     */
    private function buildRequestParameterSchema(\ReflectionType $type, array $processedClasses = [], string $fullName = ''): array
    {
        if ($type instanceof \ReflectionUnionType) {
            return $this->handleRequestUnionType($type, $processedClasses, $fullName);
        }

        $typeName = $type->getName();
        
        // Handle basic types
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
            $typeName = $type->getName();
            
            if (is_subclass_of($typeName, \Spatie\LaravelData\Data::class)) {
                $nestedParams = $this->buildSpatieDataRequestSchema($typeName, $processedClasses, $fullName);
                if (!empty($nestedParams)) {
                    return [
                        'type' => 'object',
                        'format' => null,
                        'parameters' => $nestedParams,
                    ];
                }
            }
            
            if ($this->isCollection($typeName)) {
                $types[] = 'array';
                continue;
            }
            
            $types[] = $this->mapPhpTypeToOpenApi($typeName);
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
        if ($hasSnakeCaseMapping) {
            return Str::snake($propertyName);
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
    
    /**
     * Detect parameters merged from route values in prepareForValidation method
     */
    private function detectRouteParameters(ReflectionClass $reflection): array
    {
        try {
            if (!$reflection->hasMethod('prepareForValidation')) {
                return [];
            }
            
            $method = $reflection->getMethod('prepareForValidation');
            $fileName = $method->getFileName();
            
            if (!$fileName || !file_exists($fileName)) {
                return [];
            }
            
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse(file_get_contents($fileName));
            
            $nodeFinder = new NodeFinder;
            $prepareMethod = $nodeFinder->findFirst($ast, function ($node) {
                return $node instanceof \PhpParser\Node\Stmt\ClassMethod
                    && $node->name->toString() === 'prepareForValidation';
            });
            
            if (!$prepareMethod) {
                return [];
            }
            
            $routeParameters = [];
            
            // Find $this->merge(['param' => $this->route('paramName')]) patterns
            $mergeNodes = $nodeFinder->find($prepareMethod, function ($node) {
                return $node instanceof MethodCall
                    && $node->var instanceof Variable
                    && $node->var->name === 'this'
                    && $node->name->toString() === 'merge';
            });
            
            foreach ($mergeNodes as $mergeNode) {
                if (!isset($mergeNode->args[0]) || !$mergeNode->args[0]->value instanceof Array_) {
                    continue;
                }
                
                foreach ($mergeNode->args[0]->value->items as $item) {
                    if (!$item || !$item->key instanceof String_) {
                        continue;
                    }
                    
                    $paramName = $item->key->value;
                    
                    // Check if the value is $this->route(...)
                    $isRouteParam = $nodeFinder->findFirst($item->value, function ($node) {
                        return $node instanceof MethodCall
                            && $node->var instanceof Variable
                            && $node->var->name === 'this'
                            && $node->name->toString() === 'route';
                    });
                    
                    if ($isRouteParam) {
                        $routeParameters[] = $paramName;
                    }
                }
            }
            
            return $routeParameters;
        } catch (Throwable) {
            return [];
        }
    }
    
    /**
     * Detect parameters to ignore via IgnoreDataParameter attribute
     */
    private function detectIgnoredParameters(ReflectionClass $reflection): array
    {
        try {
            $ignoredParameters = [];
            
            // Check for IgnoreDataParameter attributes on public methods
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(IgnoreDataParameter::class);
                
                foreach ($attributes as $attribute) {
                    $instance = $attribute->newInstance();
                    $params = explode(',', $instance->parameters);
                    
                    foreach ($params as $param) {
                        $ignoredParameters[] = trim($param);
                    }
                }
            }
            
            return $ignoredParameters;
        } catch (Throwable) {
            return [];
        }
    }
}
