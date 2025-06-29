<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Throwable;

class NestedArrayValidationAnalyzer
{
    private EnhancedValidationRuleAnalyzer $enhancedAnalyzer;

    public function __construct(EnhancedValidationRuleAnalyzer $enhancedAnalyzer)
    {
        $this->enhancedAnalyzer = $enhancedAnalyzer;
    }

    /**
     * Analyze nested array validation patterns
     */
    public function analyzeNestedArrayValidation(array $allRules): array
    {
        $result = [];
        $processedPaths = [];

        foreach ($allRules as $fieldPath => $rules) {
            if (str_contains($fieldPath, '.')) {
                $analyzedField = $this->analyzeNestedField($fieldPath, $rules, $allRules);
                if ($analyzedField) {
                    $result = array_merge_recursive($result, $analyzedField);
                    $processedPaths[] = $fieldPath;
                }
            }
        }

        // Remove processed nested fields from the original rules
        foreach ($processedPaths as $path) {
            unset($allRules[$path]);
        }

        return array_merge($allRules, $result);
    }

    /**
     * Analyze a specific nested field
     */
    private function analyzeNestedField(string $fieldPath, array $rules, array $allRules): ?array
    {
        try {
            $parts = explode('.', $fieldPath);
            $result = [];
            $currentLevel = &$result;

            for ($i = 0; $i < count($parts); $i++) {
                $part = $parts[$i];
                $isLast = $i === count($parts) - 1;

                if ($part === '*') {
                    // Handle wildcard (array index)
                    if (! isset($currentLevel['items'])) {
                        $currentLevel['items'] = [
                            'type' => 'object',
                            'properties' => [],
                        ];
                    }
                    $currentLevel = &$currentLevel['items']['properties'];
                } else {
                    // Handle regular property
                    if ($isLast) {
                        // This is the final property, apply the validation rules
                        $currentLevel[$part] = $this->enhancedAnalyzer->parseValidationRules($rules);

                        // Check for related wildcard rules that might define array structure
                        $arrayInfo = $this->checkForArrayStructure($fieldPath, $allRules);
                        if ($arrayInfo) {
                            $currentLevel[$part] = array_merge($currentLevel[$part], $arrayInfo);
                        }
                    } else {
                        // Intermediate property
                        if (! isset($currentLevel[$part])) {
                            $currentLevel[$part] = [
                                'type' => 'object',
                                'properties' => [],
                            ];
                        }
                        $currentLevel = &$currentLevel[$part]['properties'];
                    }
                }
            }

            return $this->restructureForRootLevel($fieldPath, $result);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check for array structure based on related wildcard rules
     */
    private function checkForArrayStructure(string $fieldPath, array $allRules): ?array
    {
        $pathPrefix = $this->getPathPrefix($fieldPath);

        // Look for related array rules
        foreach ($allRules as $rulePath => $rules) {
            if (str_starts_with($rulePath, $pathPrefix) && str_contains($rulePath, '*')) {
                return [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * Get the path prefix for finding related rules
     */
    private function getPathPrefix(string $fieldPath): string
    {
        $parts = explode('.', $fieldPath);
        array_pop(); // Remove the last part

        return implode('.', $parts);
    }

    /**
     * Restructure the nested result for the root level
     */
    private function restructureForRootLevel(string $fieldPath, array $result): array
    {
        $rootKey = explode('.', $fieldPath)[0];

        return [$rootKey => $result[$rootKey]];
    }

    /**
     * Analyze complex array validation patterns like 'items.*.field'
     */
    public function analyzeWildcardValidation(string $fieldPath, array $rules): array
    {
        $parts = explode('.', $fieldPath);
        $schema = [];
        $current = &$schema;

        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];
            $isLast = $i === count($parts) - 1;

            if ($part === '*') {
                if ($isLast) {
                    // items.*
                    $current['type'] = 'array';
                    $current['items'] = $this->enhancedAnalyzer->parseValidationRules($rules);
                } else {
                    // items.*.field
                    $current['type'] = 'array';
                    $current['items'] = [
                        'type' => 'object',
                        'properties' => [],
                    ];
                    $current = &$current['items']['properties'];
                }
            } else {
                if ($isLast) {
                    $current[$part] = $this->enhancedAnalyzer->parseValidationRules($rules);
                } else {
                    $current[$part] = [
                        'type' => 'object',
                        'properties' => [],
                    ];
                    $current = &$current[$part]['properties'];
                }
            }
        }

        return $schema;
    }

    /**
     * Detect and handle special array validation patterns
     */
    public function detectArrayPatterns(array $allRules): array
    {
        $patterns = [];

        foreach ($allRules as $fieldPath => $rules) {
            $pattern = $this->identifyPattern($fieldPath);
            if ($pattern) {
                $patterns[$fieldPath] = [
                    'pattern' => $pattern,
                    'rules' => $rules,
                    'schema' => $this->generateSchemaForPattern($pattern, $fieldPath, $rules),
                ];
            }
        }

        return $patterns;
    }

    /**
     * Identify the type of array validation pattern
     */
    private function identifyPattern(string $fieldPath): ?string
    {
        if (! str_contains($fieldPath, '.')) {
            return null;
        }

        $parts = explode('.', $fieldPath);
        $wildcardCount = count(array_filter($parts, fn ($part) => $part === '*'));

        if ($wildcardCount === 0) {
            return 'nested_object';
        } elseif ($wildcardCount === 1) {
            $wildcardPosition = array_search('*', $parts);
            if ($wildcardPosition === count($parts) - 1) {
                return 'simple_array';
            } else {
                return 'array_of_objects';
            }
        } else {
            return 'multi_dimensional_array';
        }
    }

    /**
     * Generate OpenAPI schema for specific patterns
     */
    private function generateSchemaForPattern(string $pattern, string $fieldPath, array $rules): array
    {
        return match ($pattern) {
            'simple_array' => $this->generateSimpleArraySchema($fieldPath, $rules),
            'array_of_objects' => $this->generateArrayOfObjectsSchema($fieldPath, $rules),
            'nested_object' => $this->generateNestedObjectSchema($fieldPath, $rules),
            'multi_dimensional_array' => $this->generateMultiDimensionalArraySchema($fieldPath, $rules),
            default => $this->enhancedAnalyzer->parseValidationRules($rules),
        };
    }

    /**
     * Generate schema for simple arrays (e.g., "tags.*")
     */
    private function generateSimpleArraySchema(string $fieldPath, array $rules): array
    {
        return [
            'type' => 'array',
            'items' => $this->enhancedAnalyzer->parseValidationRules($rules),
        ];
    }

    /**
     * Generate schema for arrays of objects (e.g., "users.*.name")
     */
    private function generateArrayOfObjectsSchema(string $fieldPath, array $rules): array
    {
        $parts = explode('.', $fieldPath);
        $wildcardIndex = array_search('*', $parts);
        $propertyPath = array_slice($parts, $wildcardIndex + 1);

        $itemSchema = ['type' => 'object', 'properties' => []];
        $current = &$itemSchema['properties'];

        foreach ($propertyPath as $i => $property) {
            if ($i === count($propertyPath) - 1) {
                $current[$property] = $this->enhancedAnalyzer->parseValidationRules($rules);
            } else {
                $current[$property] = ['type' => 'object', 'properties' => []];
                $current = &$current[$property]['properties'];
            }
        }

        return [
            'type' => 'array',
            'items' => $itemSchema,
        ];
    }

    /**
     * Generate schema for nested objects (e.g., "user.profile.name")
     */
    private function generateNestedObjectSchema(string $fieldPath, array $rules): array
    {
        $parts = explode('.', $fieldPath);
        $schema = ['type' => 'object', 'properties' => []];
        $current = &$schema['properties'];

        foreach ($parts as $i => $property) {
            if ($i === count($parts) - 1) {
                $current[$property] = $this->enhancedAnalyzer->parseValidationRules($rules);
            } else {
                $current[$property] = ['type' => 'object', 'properties' => []];
                $current = &$current[$property]['properties'];
            }
        }

        return $schema;
    }

    /**
     * Generate schema for multi-dimensional arrays (e.g., "matrix.*.*.value")
     */
    private function generateMultiDimensionalArraySchema(string $fieldPath, array $rules): array
    {
        $parts = explode('.', $fieldPath);
        $schema = [];
        $current = &$schema;

        foreach ($parts as $i => $part) {
            if ($part === '*') {
                $current['type'] = 'array';
                $current['items'] = [];
                $current = &$current['items'];
            } else {
                if ($i === count($parts) - 1) {
                    if (isset($current['type']) && $current['type'] === 'array') {
                        $current['items'] = [
                            'type' => 'object',
                            'properties' => [
                                $part => $this->enhancedAnalyzer->parseValidationRules($rules),
                            ],
                        ];
                    } else {
                        $current[$part] = $this->enhancedAnalyzer->parseValidationRules($rules);
                    }
                } else {
                    if (isset($current['type']) && $current['type'] === 'array') {
                        $current['items'] = [
                            'type' => 'object',
                            'properties' => [],
                        ];
                        $current = &$current['items']['properties'];
                    } else {
                        $current[$part] = ['type' => 'object', 'properties' => []];
                        $current = &$current[$part]['properties'];
                    }
                }
            }
        }

        return $schema;
    }
}
