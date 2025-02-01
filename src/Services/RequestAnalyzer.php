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

            $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
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

            return $parameters;
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

            $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
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
}
