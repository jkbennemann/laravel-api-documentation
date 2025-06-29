<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Contracts\Validation\Rule;
use ReflectionClass;
use Throwable;

class CustomValidationRuleAnalyzer
{
    /**
     * Analyze custom validation rule objects
     */
    public function analyzeCustomRule(object $rule): array
    {
        $result = [
            'type' => 'string',
            'description' => 'Must pass custom validation.',
            'format' => null,
            'required' => false,
            'nullable' => false,
            'deprecated' => false,
            'minimum' => null,
            'maximum' => null,
            'minLength' => null,
            'maxLength' => null,
            'pattern' => null,
            'enum' => null,
            'example' => null,
            'items' => null,
            'conditionalRequired' => [],
        ];

        try {
            $reflection = new ReflectionClass($rule);
            $className = $reflection->getShortName();

            // Extract meaningful description from class name
            $description = $this->extractDescriptionFromClassName($className);
            $result['description'] = $description;

            // Try to extract additional information from the rule
            $additionalInfo = $this->extractAdditionalRuleInfo($rule, $reflection);
            $result = array_merge($result, $additionalInfo);

            return $result;
        } catch (Throwable) {
            return $result;
        }
    }

    /**
     * Extract description from custom rule class name
     */
    private function extractDescriptionFromClassName(string $className): string
    {
        // Remove common suffixes
        $cleanName = preg_replace('/(Rule|Validation|Validator)$/', '', $className);

        // Convert PascalCase to words
        $words = preg_replace('/([A-Z])/', ' $1', $cleanName);
        $words = trim($words);
        $words = strtolower($words);

        // Capitalize first letter
        $description = ucfirst($words);

        return "Must pass {$description} validation.";
    }

    /**
     * Extract additional information from custom rule object
     */
    private function extractAdditionalRuleInfo(object $rule, ReflectionClass $reflection): array
    {
        $info = [];

        try {
            // Check for specific rule patterns and extract constraints
            $className = $reflection->getName();

            // Hash ID rules
            if (str_contains($className, 'HashId') || str_contains($className, 'Hashid')) {
                $info['type'] = 'string';
                $info['format'] = 'hash-id';
                $info['pattern'] = '^[a-zA-Z0-9]{8,}$';
                $info['example'] = 'abc123XYZ';
                $info['description'] = 'Must be a valid hash ID.';
            }

            // Email rules
            elseif (str_contains($className, 'Email')) {
                $info['type'] = 'string';
                $info['format'] = 'email';
                $info['example'] = 'user@example.com';
            }

            // URL rules
            elseif (str_contains($className, 'Url') || str_contains($className, 'URL')) {
                $info['type'] = 'string';
                $info['format'] = 'uri';
                $info['example'] = 'https://example.com';
            }

            // Numeric rules
            elseif (str_contains($className, 'Numeric') || str_contains($className, 'Number')) {
                $info['type'] = 'number';
            }

            // Integer rules
            elseif (str_contains($className, 'Integer') || str_contains($className, 'Int')) {
                $info['type'] = 'integer';
            }

            // Boolean rules
            elseif (str_contains($className, 'Boolean') || str_contains($className, 'Bool')) {
                $info['type'] = 'boolean';
            }

            // Array rules
            elseif (str_contains($className, 'Array') || str_contains($className, 'Collection')) {
                $info['type'] = 'array';
            }

            // Phone number rules
            elseif (str_contains($className, 'Phone')) {
                $info['type'] = 'string';
                $info['pattern'] = '^[+]?[0-9\s\-\(\)]{10,}$';
                $info['example'] = '+1234567890';
            }

            // UUID rules
            elseif (str_contains($className, 'Uuid') || str_contains($className, 'UUID')) {
                $info['type'] = 'string';
                $info['format'] = 'uuid';
                $info['example'] = '123e4567-e89b-12d3-a456-426614174000';
            }

            // Date rules
            elseif (str_contains($className, 'Date')) {
                $info['type'] = 'string';
                $info['format'] = 'date';
            }

            // DateTime rules
            elseif (str_contains($className, 'DateTime') || str_contains($className, 'Timestamp')) {
                $info['type'] = 'string';
                $info['format'] = 'date-time';
            }

            // Try to extract constraints from rule properties
            $constraints = $this->extractConstraintsFromRule($rule, $reflection);
            $info = array_merge($info, $constraints);

        } catch (Throwable) {
            // Ignore extraction errors
        }

        return $info;
    }

    /**
     * Extract constraints from rule object properties
     */
    private function extractConstraintsFromRule(object $rule, ReflectionClass $reflection): array
    {
        $constraints = [];

        try {
            // Look for common constraint properties
            $properties = $reflection->getProperties();

            foreach ($properties as $property) {
                $property->setAccessible(true);
                $name = $property->getName();
                $value = $property->getValue($rule);

                match ($name) {
                    'min', 'minimum' => $constraints['minimum'] = (int) $value,
                    'max', 'maximum' => $constraints['maximum'] = (int) $value,
                    'minLength', 'min_length' => $constraints['minLength'] = (int) $value,
                    'maxLength', 'max_length' => $constraints['maxLength'] = (int) $value,
                    'pattern', 'regex' => $constraints['pattern'] = (string) $value,
                    'values', 'allowed', 'enum' => $constraints['enum'] = is_array($value) ? $value : [$value],
                    'example' => $constraints['example'] = $value,
                    default => null,
                };
            }

        } catch (Throwable) {
            // Ignore property extraction errors
        }

        return $constraints;
    }

    /**
     * Check if object is a custom validation rule
     */
    public function isCustomValidationRule(object $rule): bool
    {
        try {
            // Check if it implements Laravel's Rule interface
            if ($rule instanceof Rule) {
                return true;
            }

            // Check if it has validation methods
            $reflection = new ReflectionClass($rule);

            return $reflection->hasMethod('passes') && $reflection->hasMethod('message');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Extract validation message from custom rule
     */
    public function extractValidationMessage(object $rule): ?string
    {
        try {
            if (method_exists($rule, 'message')) {
                $message = $rule->message();
                if (is_string($message)) {
                    return $message;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Analyze rule method to understand validation logic
     */
    public function analyzeRuleMethod(object $rule): array
    {
        $analysis = [];

        try {
            $reflection = new ReflectionClass($rule);

            if (! $reflection->hasMethod('passes')) {
                return $analysis;
            }

            $passesMethod = $reflection->getMethod('passes');
            $parameters = $passesMethod->getParameters();

            // Analyze parameters to understand what the rule validates
            foreach ($parameters as $param) {
                $paramType = $param->getType();
                if ($paramType) {
                    $typeName = $paramType->getName();
                    match ($typeName) {
                        'string' => $analysis['validates'] = 'string values',
                        'int', 'integer' => $analysis['validates'] = 'integer values',
                        'float', 'double' => $analysis['validates'] = 'numeric values',
                        'bool', 'boolean' => $analysis['validates'] = 'boolean values',
                        'array' => $analysis['validates'] = 'array values',
                        default => $analysis['validates'] = 'custom values',
                    };
                }
            }

        } catch (Throwable) {
            // Ignore analysis errors
        }

        return $analysis;
    }

    /**
     * Get suggestions for documenting custom rules
     */
    public function getSuggestions(object $rule): array
    {
        $suggestions = [];

        try {
            $reflection = new ReflectionClass($rule);
            $className = $reflection->getName();

            $suggestions[] = "Consider adding a descriptive docblock to {$className}";
            $suggestions[] = 'Add example values for the validation rule';

            if (! $reflection->hasMethod('__toString')) {
                $suggestions[] = 'Implement __toString() method for better rule description';
            }

            // Check if the rule has configurable constraints
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
            if (! empty($properties)) {
                $suggestions[] = 'Consider making constraint properties protected and adding getters';
            }

        } catch (Throwable) {
            // Ignore suggestion generation errors
        }

        return $suggestions;
    }
}
