<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class EnhancedValidationRuleAnalyzer
{
    private EnumAnalyzer $enumAnalyzer;

    private CustomValidationRuleAnalyzer $customRuleAnalyzer;

    public function __construct()
    {
        $this->enumAnalyzer = new EnumAnalyzer;
        $this->customRuleAnalyzer = new CustomValidationRuleAnalyzer;
    }

    /**
     * Parse enhanced Laravel validation rules into OpenAPI schema
     */
    public function parseValidationRules(array $rules): array
    {
        $type = 'string'; // Default type
        $format = null;
        $required = false;
        $description = [];
        $deprecated = false;
        $minimum = null;
        $maximum = null;
        $minLength = null;
        $maxLength = null;
        $pattern = null;
        $enum = null;
        $example = null;
        $items = null;
        $nullable = false;
        $conditionalRequired = [];

        foreach ($rules as $rule) {
            $parsedRule = $this->parseIndividualRule($rule);

            // Merge results from individual rule parsing
            if ($parsedRule['type']) {
                $type = $parsedRule['type'];
            }
            if ($parsedRule['format']) {
                $format = $parsedRule['format'];
            }
            if ($parsedRule['required']) {
                $required = true;
            }
            if ($parsedRule['nullable']) {
                $nullable = true;
            }
            if ($parsedRule['deprecated']) {
                $deprecated = true;
            }
            if ($parsedRule['minimum'] !== null) {
                $minimum = $parsedRule['minimum'];
            }
            if ($parsedRule['maximum'] !== null) {
                $maximum = $parsedRule['maximum'];
            }
            if ($parsedRule['minLength'] !== null) {
                $minLength = $parsedRule['minLength'];
            }
            if ($parsedRule['maxLength'] !== null) {
                $maxLength = $parsedRule['maxLength'];
            }
            if ($parsedRule['pattern']) {
                $pattern = $parsedRule['pattern'];
            }
            if ($parsedRule['enum']) {
                $enum = $parsedRule['enum'];
            }
            if ($parsedRule['example']) {
                $example = $parsedRule['example'];
            }
            if ($parsedRule['items']) {
                $items = $parsedRule['items'];
            }
            if ($parsedRule['conditionalRequired']) {
                $conditionalRequired = array_merge($conditionalRequired, $parsedRule['conditionalRequired']);
            }

            // Accumulate descriptions
            if ($parsedRule['description']) {
                $description[] = $parsedRule['description'];
            }
        }

        // Build the result
        $result = [
            'type' => $type,
            'required' => $required,
        ];

        if ($nullable) {
            $result['nullable'] = true;
        }

        if ($format) {
            $result['format'] = $format;
        }

        if (! empty($description)) {
            $result['description'] = implode(' ', array_unique($description));
        }

        if ($deprecated) {
            $result['deprecated'] = true;
        }

        if ($minimum !== null) {
            $result['minimum'] = $minimum;
        }

        if ($maximum !== null) {
            $result['maximum'] = $maximum;
        }

        if ($minLength !== null) {
            $result['minLength'] = $minLength;
        }

        if ($maxLength !== null) {
            $result['maxLength'] = $maxLength;
        }

        if ($pattern !== null) {
            $result['pattern'] = $pattern;
        }

        if ($enum !== null) {
            $result['enum'] = $enum;
        }

        if ($example !== null) {
            $result['example'] = $example;
        }

        if ($type === 'array' && $items !== null) {
            $result['items'] = $items;
        }

        if (! empty($conditionalRequired)) {
            $result['conditionalRequired'] = $conditionalRequired;
        }

        return $result;
    }

    /**
     * Parse individual validation rule (string, Rule object, or custom)
     */
    private function parseIndividualRule($rule): array
    {
        $result = [
            'type' => null,
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
            'description' => null,
            'conditionalRequired' => [],
        ];

        if (is_string($rule)) {
            return $this->parseStringRule($rule);
        }

        if (is_object($rule)) {
            return $this->parseObjectRule($rule);
        }

        return $result;
    }

    /**
     * Parse string-based validation rules
     */
    private function parseStringRule(string $rule): array
    {
        $result = [
            'type' => null,
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
            'description' => null,
            'conditionalRequired' => [],
        ];

        $rule = trim($rule);
        if (empty($rule)) {
            return $result;
        }

        // Parse rule with parameters
        $ruleName = $rule;
        $ruleParams = [];
        if (str_contains($rule, ':')) {
            [$ruleName, $paramStr] = explode(':', $rule, 2);
            $ruleParams = explode(',', $paramStr);
        }

        return $this->processRuleName($ruleName, $ruleParams);
    }

    /**
     * Parse object-based validation rules (Rule objects, Enums, etc.)
     */
    private function parseObjectRule(object $rule): array
    {
        $result = [
            'type' => null,
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
            'description' => null,
            'conditionalRequired' => [],
        ];

        // Handle Enum rules (PHP 8.1+)
        if ($rule instanceof Enum) {
            return $this->parseEnumRule($rule);
        }

        // Handle Laravel Rule objects
        if (method_exists($rule, '__toString')) {
            $ruleString = (string) $rule;

            return $this->parseStringRule($ruleString);
        }

        // Handle custom rule objects
        if ($this->customRuleAnalyzer->isCustomValidationRule($rule)) {
            $customRuleInfo = $this->customRuleAnalyzer->analyzeCustomRule($rule);

            return array_merge($result, $customRuleInfo);
        }

        return $result;
    }

    /**
     * Parse PHP 8.1+ Enum validation rules
     */
    private function parseEnumRule(Enum $enumRule): array
    {
        $result = [
            'type' => 'string',
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
            'description' => 'Must be a valid enum value.',
            'conditionalRequired' => [],
        ];

        $enumInfo = $this->enumAnalyzer->analyzeEnumFromRule($enumRule);

        if ($enumInfo) {
            $result['type'] = $enumInfo['type'];
            $result['enum'] = $enumInfo['enum'];
            $result['description'] = $enumInfo['description'];
            $result['example'] = $enumInfo['example'];

            // Add extended enum information if available
            if (isset($enumInfo['x-enum-descriptions'])) {
                $result['x-enum-descriptions'] = $enumInfo['x-enum-descriptions'];
            }
        }

        return $result;
    }

    /**
     * Process rule name and parameters
     */
    private function processRuleName(string $ruleName, array $ruleParams): array
    {
        $result = [
            'type' => null,
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
            'description' => null,
            'conditionalRequired' => [],
        ];

        switch (strtolower($ruleName)) {
            // Enhanced conditional validation rules
            case 'required_if':
                if (count($ruleParams) >= 2) {
                    $result['conditionalRequired'][] = [
                        'type' => 'required_if',
                        'field' => $ruleParams[0],
                        'value' => $ruleParams[1],
                        'description' => "Required when {$ruleParams[0]} is {$ruleParams[1]}.",
                    ];
                }
                break;

            case 'required_unless':
                if (count($ruleParams) >= 2) {
                    $result['conditionalRequired'][] = [
                        'type' => 'required_unless',
                        'field' => $ruleParams[0],
                        'value' => $ruleParams[1],
                        'description' => "Required unless {$ruleParams[0]} is {$ruleParams[1]}.",
                    ];
                }
                break;

            case 'required_with':
                if (! empty($ruleParams)) {
                    $fields = implode(', ', $ruleParams);
                    $result['conditionalRequired'][] = [
                        'type' => 'required_with',
                        'fields' => $ruleParams,
                        'description' => "Required when any of these fields are present: {$fields}.",
                    ];
                }
                break;

            case 'required_with_all':
                if (! empty($ruleParams)) {
                    $fields = implode(', ', $ruleParams);
                    $result['conditionalRequired'][] = [
                        'type' => 'required_with_all',
                        'fields' => $ruleParams,
                        'description' => "Required when all of these fields are present: {$fields}.",
                    ];
                }
                break;

            case 'required_without':
                if (! empty($ruleParams)) {
                    $fields = implode(', ', $ruleParams);
                    $result['conditionalRequired'][] = [
                        'type' => 'required_without',
                        'fields' => $ruleParams,
                        'description' => "Required when any of these fields are missing: {$fields}.",
                    ];
                }
                break;

            case 'required_without_all':
                if (! empty($ruleParams)) {
                    $fields = implode(', ', $ruleParams);
                    $result['conditionalRequired'][] = [
                        'type' => 'required_without_all',
                        'fields' => $ruleParams,
                        'description' => "Required when all of these fields are missing: {$fields}.",
                    ];
                }
                break;

            case 'sometimes':
                $result['required'] = false;
                $result['description'] = 'Optional field that is validated only when present.';
                break;

                // Enhanced type rules
            case 'array':
                $result['type'] = 'array';
                $result['items'] = ['type' => 'string']; // Default item type
                $result['description'] = 'Must be an array.';
                break;

            case 'hash_id':
            case 'hashid':
                $result['type'] = 'string';
                $result['format'] = 'hash-id';
                $result['pattern'] = '^[a-zA-Z0-9]{8,}$';
                $result['description'] = 'Must be a valid hash ID.';
                $result['example'] = 'abc123XYZ';
                break;

                // Enhanced file validation
            case 'mimes':
                $result['type'] = 'string';
                $result['format'] = 'binary';
                if (! empty($ruleParams)) {
                    $mimeTypes = implode(', ', $ruleParams);
                    $result['description'] = "Must be a file of type: {$mimeTypes}.";
                }
                break;

            case 'mimetypes':
                $result['type'] = 'string';
                $result['format'] = 'binary';
                if (! empty($ruleParams)) {
                    $mimeTypes = implode(', ', $ruleParams);
                    $result['description'] = "Must be a file with MIME type: {$mimeTypes}.";
                }
                break;

                // Enhanced numeric rules
            case 'digits':
                $result['type'] = 'string';
                if (! empty($ruleParams[0])) {
                    $digits = (int) $ruleParams[0];
                    $result['minLength'] = $digits;
                    $result['maxLength'] = $digits;
                    $result['pattern'] = '^\\d{'.$digits.'}$';
                    $result['description'] = "Must be exactly {$digits} digits.";
                }
                break;

            case 'digits_between':
                $result['type'] = 'string';
                if (count($ruleParams) >= 2) {
                    $min = (int) $ruleParams[0];
                    $max = (int) $ruleParams[1];
                    $result['minLength'] = $min;
                    $result['maxLength'] = $max;
                    $result['pattern'] = '^\\d{'.$min.','.$max.'}$';
                    $result['description'] = "Must be between {$min} and {$max} digits.";
                }
                break;

                // Enhanced date rules
            case 'before':
                $result['type'] = 'string';
                $result['format'] = 'date';
                if (! empty($ruleParams[0])) {
                    $result['description'] = "Must be a date before {$ruleParams[0]}.";
                }
                break;

            case 'after':
                $result['type'] = 'string';
                $result['format'] = 'date';
                if (! empty($ruleParams[0])) {
                    $result['description'] = "Must be a date after {$ruleParams[0]}.";
                }
                break;

            case 'before_or_equal':
                $result['type'] = 'string';
                $result['format'] = 'date';
                if (! empty($ruleParams[0])) {
                    $result['description'] = "Must be a date before or equal to {$ruleParams[0]}.";
                }
                break;

            case 'after_or_equal':
                $result['type'] = 'string';
                $result['format'] = 'date';
                if (! empty($ruleParams[0])) {
                    $result['description'] = "Must be a date after or equal to {$ruleParams[0]}.";
                }
                break;

                // Password rules
            case 'password':
                $result['type'] = 'string';
                $result['format'] = 'password';
                $result['description'] = 'Must be a valid password.';
                break;

                // Enum rules (for manual enum references)
            case 'enum':
                if (! empty($ruleParams[0])) {
                    $enumClass = $ruleParams[0];
                    $enumInfo = $this->enumAnalyzer->analyzeEnumClass($enumClass);
                    if ($enumInfo) {
                        $result['type'] = $enumInfo['type'];
                        $result['enum'] = $enumInfo['enum'];
                        $result['description'] = $enumInfo['description'];
                        $result['example'] = $enumInfo['example'];

                        if (isset($enumInfo['x-enum-descriptions'])) {
                            $result['x-enum-descriptions'] = $enumInfo['x-enum-descriptions'];
                        }
                    } else {
                        $result['description'] = 'Must be a valid enum value.';
                    }
                } else {
                    $result['description'] = 'Must be a valid enum value.';
                }
                break;

                // Default case - return basic rule processing
            default:
                return $this->processBasicRule($ruleName, $ruleParams);
        }

        return $result;
    }

    /**
     * Process basic validation rules (existing logic)
     */
    private function processBasicRule(string $ruleName, array $ruleParams): array
    {
        $result = [
            'type' => 'string',
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
            'description' => null,
            'conditionalRequired' => [],
        ];

        switch (strtolower($ruleName)) {
            case 'required':
                $result['required'] = true;
                $result['description'] = 'Required.';
                break;

            case 'nullable':
                $result['nullable'] = true;
                $result['description'] = 'Can be null.';
                break;

            case 'string':
                $result['type'] = 'string';
                $result['description'] = 'Must be a string.';
                break;

            case 'integer':
            case 'int':
                $result['type'] = 'integer';
                $result['description'] = 'Must be an integer.';
                break;

            case 'numeric':
                $result['type'] = 'number';
                $result['description'] = 'Must be a number.';
                break;

            case 'boolean':
            case 'bool':
                $result['type'] = 'boolean';
                $result['description'] = 'Must be a boolean.';
                break;

            case 'email':
                $result['type'] = 'string';
                $result['format'] = 'email';
                $result['description'] = 'Must be a valid email address.';
                $result['example'] = 'user@example.com';
                break;

            case 'url':
                $result['type'] = 'string';
                $result['format'] = 'uri';
                $result['description'] = 'Must be a valid URL.';
                $result['example'] = 'https://example.com';
                break;

            case 'uuid':
                $result['type'] = 'string';
                $result['format'] = 'uuid';
                $result['description'] = 'Must be a valid UUID.';
                $result['example'] = '123e4567-e89b-12d3-a456-426614174000';
                break;

            case 'regex':
                $result['type'] = 'string';
                if (! empty($ruleParams[0])) {
                    $pattern = $ruleParams[0];
                    $result['pattern'] = $pattern;

                    // Analyze the regex pattern to provide a meaningful description
                    $description = $this->analyzeRegexPattern($pattern);
                    $result['description'] = $description;

                    // Try to generate an example based on the pattern
                    $example = $this->generateRegexExample($pattern);
                    if ($example) {
                        $result['example'] = $example;
                    }
                }
                break;

            case 'date':
                $result['type'] = 'string';
                $result['format'] = 'date';
                $result['description'] = 'Must be a valid date.';
                break;

            case 'in':
                if (! empty($ruleParams)) {
                    $result['enum'] = $ruleParams;
                    $result['description'] = 'Must be one of: '.implode(', ', $ruleParams).'.';
                    $result['example'] = $ruleParams[0];
                }
                break;

            case 'min':
                if (! empty($ruleParams[0])) {
                    $result['minimum'] = (int) $ruleParams[0];
                    $result['description'] = "Minimum value: {$ruleParams[0]}.";
                }
                break;

            case 'max':
                if (! empty($ruleParams[0])) {
                    $result['maximum'] = (int) $ruleParams[0];
                    $result['description'] = "Maximum value: {$ruleParams[0]}.";
                }
                break;
        }

        return $result;
    }

    /**
     * Analyze a regex pattern to provide a meaningful description
     */
    private function analyzeRegexPattern(string $pattern): string
    {
        // Remove delimiters and flags from pattern
        $cleanPattern = $this->cleanRegexPattern($pattern);

        // Common regex pattern descriptions
        $commonPatterns = [
            '/^\d+$/' => 'Must contain only digits.',
            '/^[a-zA-Z]+$/' => 'Must contain only letters.',
            '/^[a-zA-Z0-9]+$/' => 'Must contain only letters and numbers.',
            '/^[a-zA-Z0-9_-]+$/' => 'Must contain only letters, numbers, underscores, and hyphens.',
            '/^\w+$/' => 'Must contain only word characters (letters, numbers, underscores).',
            '/^.{10}-.{10}$/' => 'Must be in format: 10 characters, hyphen, 10 characters.',
            '/.{10}-.{10}/' => 'Must be in format: 10 characters, hyphen, 10 characters.',
            '/^[0-9]{2,4}$/' => 'Must be 2 to 4 digits.',
            '/^[A-Z]{2,3}$/' => 'Must be 2 to 3 uppercase letters.',
            '/^[a-z]{3,}$/' => 'Must be at least 3 lowercase letters.',
            '/^\+?[1-9]\d{1,14}$/' => 'Must be a valid phone number.',
        ];

        // Check for exact matches first
        if (isset($commonPatterns[$pattern])) {
            return $commonPatterns[$pattern];
        }

        // Analyze pattern components for dynamic description
        $description = 'Must match the pattern: '.$pattern;

        // Try to provide more specific descriptions based on pattern analysis
        if (preg_match('/\{(\d+)\}/', $cleanPattern, $matches)) {
            $length = $matches[1];
            $description = "Must be exactly {$length} characters matching pattern: {$pattern}";
        } elseif (preg_match('/\{(\d+),(\d+)\}/', $cleanPattern, $matches)) {
            $min = $matches[1];
            $max = $matches[2];
            $description = "Must be {$min} to {$max} characters matching pattern: {$pattern}";
        } elseif (preg_match('/\{(\d+),\}/', $cleanPattern, $matches)) {
            $min = $matches[1];
            $description = "Must be at least {$min} characters matching pattern: {$pattern}";
        } elseif (str_contains($cleanPattern, '\d')) {
            $description = "Must contain digits and match pattern: {$pattern}";
        } elseif (str_contains($cleanPattern, '[a-zA-Z]') || str_contains($cleanPattern, '[A-Z]') || str_contains($cleanPattern, '[a-z]')) {
            $description = "Must contain letters and match pattern: {$pattern}";
        }

        return $description;
    }

    /**
     * Generate an example value based on a regex pattern
     */
    private function generateRegexExample(string $pattern): ?string
    {
        // Remove delimiters and flags from pattern
        $cleanPattern = $this->cleanRegexPattern($pattern);

        // Common regex pattern examples
        $commonExamples = [
            '/^\d+$/' => '123456',
            '/^[a-zA-Z]+$/' => 'example',
            '/^[a-zA-Z0-9]+$/' => 'example123',
            '/^[a-zA-Z0-9_-]+$/' => 'example_123',
            '/^\w+$/' => 'example_123',
            '/^.{10}-.{10}$/' => 'abcd123456-xyz7890123',
            '/.{10}-.{10}/' => 'abcd123456-xyz7890123',
            '/^[0-9]{2,4}$/' => '1234',
            '/^[A-Z]{2,3}$/' => 'ABC',
            '/^[a-z]{3,}$/' => 'example',
            '/^\+?[1-9]\d{1,14}$/' => '+1234567890',
        ];

        // Check for exact matches first
        if (isset($commonExamples[$pattern])) {
            return $commonExamples[$pattern];
        }

        // Generate simple examples based on pattern analysis
        if (preg_match('/\{(\d+)\}/', $cleanPattern)) {
            // Pattern with exact length - generate appropriate example
            if (str_contains($cleanPattern, '\d') || str_contains($cleanPattern, '[0-9]')) {
                return '1234567890'; // Numeric example
            } elseif (str_contains($cleanPattern, '[a-zA-Z]') || str_contains($cleanPattern, '[A-Z]')) {
                return 'ABCDEFGHIJ'; // Letter example
            } else {
                return 'example123'; // Mixed example
            }
        }

        // For complex patterns, return null to avoid incorrect examples
        return null;
    }

    /**
     * Clean regex pattern by removing delimiters and flags
     */
    private function cleanRegexPattern(string $pattern): string
    {
        // Remove common delimiters (/, #, ~, etc.) and flags (i, m, s, etc.)
        return preg_replace('/^[\/~#!@](.+)[\/~#!@][a-zA-Z]*$/', '$1', $pattern);
    }
}
