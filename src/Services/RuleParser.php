<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

class RuleParser
{
    public static function parse(array $rules): array
    {
        return (new self())->groupRules($rules);
    }

    private function groupRules(array $rules): array
    {
        $tree = [];

        foreach ($rules as $key => $rule) {
            // Ensure $rule is always an array; if it's a string, split it by '|'
            if (is_string($rule)) {
                $rule = explode('|', $rule);
            }

            // Separate the base parameter from nested ones
            $segments = explode('.', $key);
            $base = $segments[0];

            if (!isset($tree[$base])) {
                $tree[$base] = [
                    'name' => $base,
                    'description' => null,
                    'type' => 'array', // assuming 'base' is always an array from the example
                    'format' => null,
                    'required' => in_array('required', $rule),
                    'deprecated' => false,
                    'parameters' => [],
                ];
            }

            // If the key has nested parameters (like 'base.parameter_1')
            if (count($segments) > 1) {
                $parameterName = $segments[1];
                $type = $this->getRuleType($rule);

                $tree[$base]['parameters'][$parameterName] = [
                    'name' => $parameterName,
                    'description' => null,
                    'type' => $type,
                    'format' => $this->getFormat($rule),
                    'required' => in_array('required', $rule),
                    'deprecated' => false,
                    'parameters' => [],
                ];
            } else {
                // Handle single top-level parameters
                $base = $segments[0];
                $type = $this->getRuleType($rule);

                $tree[$base] = [
                    'name' => $base,
                    'description' => null,
                    'type' => $type,
                    'format' => $this->getFormat($rule),
                    'required' => in_array('required', $rule),
                    'deprecated' => false,
                    'parameters' => [],
                ];
            }
        }

        return $tree;
    }

    private function getFormat(mixed $rule): ?string
    {
        // If the rule is an array, find the format rule
        if (is_array($rule)) {
            foreach ($rule as $r) {
                if (str_contains($r, 'email')) {
                    return 'email';
                }
                if (str_contains($r, 'uuid')) {
                    return 'uuid';
                }
                // Add more format checks here
            }
        }

        return null;
    }

    private function getRuleType(array $parsedRules): string
    {
        // Determine the type based on the validation rules
        if (in_array('string', $parsedRules) || in_array('email', $parsedRules)) {
            return 'string';
        } elseif (in_array('integer', $parsedRules) || in_array('numeric', $parsedRules) || in_array('int', $parsedRules)) {
            return 'integer';
        } elseif (in_array('array', $parsedRules)) {
            return 'array';
        } elseif (in_array('boolean', $parsedRules) || in_array('bool', $parsedRules)) {
            return 'boolean';
        }

        // Default to string if no specific type is defined
        return 'string';
    }
}
