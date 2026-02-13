<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Schema;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class ValidationRuleMapper
{
    /** @var array<string, array{type: string, format?: string, pattern?: string}> */
    private array $ruleTypes;

    public function __construct(array $config = [])
    {
        $this->ruleTypes = $config['smart_requests']['rule_types'] ?? $this->defaultRuleTypes();
    }

    /**
     * Map a set of Laravel validation rules for a single field to a SchemaObject.
     *
     * @param  string[]|string  $rules
     */
    public function mapRules(array|string $rules): SchemaObject
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        $schema = new SchemaObject(type: 'string');
        $hasTypeRule = false;

        foreach ($rules as $rule) {
            $rule = $this->normalizeRule($rule);
            $parts = explode(':', $rule, 2);
            $ruleName = $parts[0];
            $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

            // Type rules
            if (isset($this->ruleTypes[$ruleName])) {
                $mapping = $this->ruleTypes[$ruleName];
                $schema->type = $mapping['type'];
                if (isset($mapping['format'])) {
                    $schema->format = $mapping['format'];
                }
                if (isset($mapping['pattern'])) {
                    $schema->pattern = $mapping['pattern'];
                }
                $hasTypeRule = true;

                continue;
            }

            // Constraint rules
            $this->applyConstraint($schema, $ruleName, $params);
        }

        if (! $hasTypeRule) {
            $schema->type = 'string';
        }

        return $schema;
    }

    /**
     * Determine if a field is required based on its rules.
     *
     * @param  string[]|string  $rules
     */
    public function isRequired(array|string $rules): bool
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $rule) {
            $rule = $this->normalizeRule($rule);
            if ($rule === 'required') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the field is nullable.
     *
     * @param  string[]|string  $rules
     */
    public function isNullable(array|string $rules): bool
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $rule) {
            if ($this->normalizeRule($rule) === 'nullable') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any field in the rules array has a file upload rule.
     *
     * @param  array<string, string[]|string>  $rules
     */
    public function hasFileUpload(array $rules): bool
    {
        $fileRules = ['file', 'image', 'mimes', 'mimetypes'];

        foreach ($rules as $fieldRules) {
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            foreach ($fieldRules as $rule) {
                $ruleName = explode(':', $this->normalizeRule($rule), 2)[0];
                if (in_array($ruleName, $fileRules, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build a complete SchemaObject from a flat validation rules array.
     * Handles nested rules like 'address.street' and array rules like 'items.*'.
     *
     * @param  array<string, string[]|string>  $rules
     */
    public function mapAllRules(array $rules): SchemaObject
    {
        $properties = [];
        $required = [];

        // First pass: separate top-level and nested rules
        $topLevel = [];
        $nested = [];

        foreach ($rules as $field => $fieldRules) {
            if (str_contains($field, '.')) {
                $nested[$field] = $fieldRules;
            } else {
                $topLevel[$field] = $fieldRules;
            }
        }

        // Process top-level fields
        foreach ($topLevel as $field => $fieldRules) {
            $schema = $this->mapRules($fieldRules);

            if ($this->isNullable($fieldRules)) {
                $schema->nullable = true;
            }

            if ($this->isRequired($fieldRules)) {
                $required[] = $field;
            }

            $properties[$field] = $schema;
        }

        // Process nested rules
        foreach ($nested as $field => $fieldRules) {
            $this->applyNestedRule($properties, $required, $field, $fieldRules);
        }

        // Handle 'confirmed' rule: add {field}_confirmation
        foreach ($topLevel as $field => $fieldRules) {
            if ($this->hasRule($fieldRules, 'confirmed')) {
                $confirmField = $field.'_confirmation';
                if (! isset($properties[$confirmField])) {
                    $properties[$confirmField] = clone $properties[$field];
                    if (in_array($field, $required, true)) {
                        $required[] = $confirmField;
                    }
                }
            }
        }

        return SchemaObject::object($properties, ! empty($required) ? $required : null);
    }

    /**
     * @param  array<string, SchemaObject>  $properties
     * @param  string[]  $required
     */
    private function applyNestedRule(array &$properties, array &$required, string $dotPath, array|string $rules): void
    {
        // Base case: simple field with no dots — treat as leaf
        if (! str_contains($dotPath, '.')) {
            $schema = $this->mapRules($rules);

            if ($this->isNullable($rules)) {
                $schema->nullable = true;
            }

            if ($this->isRequired($rules)) {
                $required[] = $dotPath;
            }

            $properties[$dotPath] = $schema;

            return;
        }

        $parts = explode('.', $dotPath);
        $topField = $parts[0];
        $remaining = array_slice($parts, 1);

        // Handle array wildcard: items.* or items.*.name
        if (count($remaining) >= 1 && $remaining[0] === '*') {
            // Ensure parent is array type
            if (! isset($properties[$topField])) {
                $properties[$topField] = new SchemaObject(type: 'array');
            }
            $properties[$topField]->type = 'array';

            if (count($remaining) === 1) {
                // items.* → array item schema
                $properties[$topField]->items = $this->mapRules($rules);
            } else {
                // items.*.name → array of objects
                $subField = implode('.', array_slice($remaining, 1));
                if ($properties[$topField]->items === null) {
                    $properties[$topField]->items = SchemaObject::object();
                }
                $itemProps = $properties[$topField]->items->properties ?? [];
                $itemRequired = $properties[$topField]->items->required ?? [];

                $this->applyNestedRule($itemProps, $itemRequired, $subField, $rules);

                $properties[$topField]->items->properties = $itemProps;
                if (! empty($itemRequired)) {
                    $properties[$topField]->items->required = $itemRequired;
                }
            }

            return;
        }

        // Handle nested objects: address.street
        if (! isset($properties[$topField])) {
            $properties[$topField] = SchemaObject::object();
        }

        if (count($remaining) === 1) {
            $childField = $remaining[0];
            $childSchema = $this->mapRules($rules);

            if ($this->isNullable($rules)) {
                $childSchema->nullable = true;
            }

            $subProps = $properties[$topField]->properties ?? [];
            $subRequired = $properties[$topField]->required ?? [];

            $subProps[$childField] = $childSchema;

            if ($this->isRequired($rules)) {
                $subRequired[] = $childField;
            }

            $properties[$topField]->properties = $subProps;
            if (! empty($subRequired)) {
                $properties[$topField]->required = $subRequired;
            }
        } else {
            // Deeper nesting
            $subProps = $properties[$topField]->properties ?? [];
            $subRequired = $properties[$topField]->required ?? [];
            $subField = implode('.', $remaining);
            $this->applyNestedRule($subProps, $subRequired, $subField, $rules);
            $properties[$topField]->properties = $subProps;
            if (! empty($subRequired)) {
                $properties[$topField]->required = $subRequired;
            }
        }
    }

    private function applyConstraint(SchemaObject $schema, string $rule, array $params): void
    {
        match ($rule) {
            'min' => $this->applyMin($schema, $params),
            'max' => $this->applyMax($schema, $params),
            'between' => $this->applyBetween($schema, $params),
            'size' => $this->applySize($schema, $params),
            'in' => $schema->enum = $params,
            'not_in' => null, // Can't represent in OpenAPI easily
            'regex' => $schema->pattern = $params[0] ?? null,
            'mimes' => $this->applyMimes($schema, $params),
            'mimetypes' => $this->applyMimes($schema, $params),
            'nullable' => $schema->nullable = true,
            'confirmed' => null, // UX rule, not schema
            'exists' => null, // DB rule, not schema
            'unique' => null, // DB rule, not schema
            'required_if' => $this->applyConditionalDescription($schema, 'Required if', $params),
            'required_unless' => $this->applyConditionalDescription($schema, 'Required unless', $params),
            'required_with' => $this->applyConditionalDescription($schema, 'Required with', $params),
            'required_with_all' => $this->applyConditionalDescription($schema, 'Required with all of', $params),
            'required_without' => $this->applyConditionalDescription($schema, 'Required without', $params),
            'required_without_all' => $this->applyConditionalDescription($schema, 'Required without all of', $params),
            'prohibited_if' => $this->applyConditionalDescription($schema, 'Prohibited if', $params),
            'prohibited_unless' => $this->applyConditionalDescription($schema, 'Prohibited unless', $params),
            'exclude_if' => $this->applyConditionalDescription($schema, 'Excluded if', $params),
            'exclude_unless' => $this->applyConditionalDescription($schema, 'Excluded unless', $params),
            'sometimes' => null, // Conditional rule
            'present' => null, // Presence rule
            'prohibited' => null,
            'accepted' => $schema->type = 'boolean',
            default => null,
        };
    }

    private function applyMin(SchemaObject $schema, array $params): void
    {
        if (empty($params)) {
            return;
        }

        $value = (int) $params[0];

        if (in_array($schema->type, ['integer', 'number'])) {
            $schema->minimum = $value;
        } elseif ($schema->type === 'array') {
            $schema->minItems = $value;
        } else {
            $schema->minLength = $value;
        }
    }

    private function applyMax(SchemaObject $schema, array $params): void
    {
        if (empty($params)) {
            return;
        }

        $value = (int) $params[0];

        if (in_array($schema->type, ['integer', 'number'])) {
            $schema->maximum = $value;
        } elseif ($schema->type === 'array') {
            $schema->maxItems = $value;
        } else {
            $schema->maxLength = $value;
        }
    }

    private function applyBetween(SchemaObject $schema, array $params): void
    {
        if (count($params) < 2) {
            return;
        }

        $this->applyMin($schema, [$params[0]]);
        $this->applyMax($schema, [$params[1]]);
    }

    private function applySize(SchemaObject $schema, array $params): void
    {
        if (empty($params)) {
            return;
        }

        $value = (int) $params[0];

        if (in_array($schema->type, ['integer', 'number'])) {
            $schema->minimum = $value;
            $schema->maximum = $value;
        } elseif ($schema->type === 'array') {
            $schema->minItems = $value;
            $schema->maxItems = $value;
        } else {
            $schema->minLength = $value;
            $schema->maxLength = $value;
        }
    }

    private function applyMimes(SchemaObject $schema, array $params): void
    {
        $schema->type = 'string';
        $schema->format = 'binary';

        if (! empty($params)) {
            $schema->description = 'Accepted types: '.implode(', ', $params);
        }
    }

    private function applyConditionalDescription(SchemaObject $schema, string $prefix, array $params): void
    {
        if (empty($params)) {
            return;
        }

        // Format: required_if:field,value → "Required if field = value"
        // Format: required_with:field1,field2 → "Required with field1, field2"
        if (str_contains($prefix, 'if') || str_contains($prefix, 'unless')) {
            $field = $params[0] ?? '';
            $values = array_slice($params, 1);
            $condition = $field.($values !== [] ? ' = '.implode(', ', $values) : '');
        } else {
            $condition = implode(', ', $params);
        }

        $description = $prefix.' '.$condition;
        $schema->description = $schema->description
            ? $schema->description.' — '.$description
            : $description;
    }

    private function hasRule(array|string $rules, string $targetRule): bool
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $rule) {
            $normalized = $this->normalizeRule($rule);
            $name = explode(':', $normalized, 2)[0];
            if ($name === $targetRule) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRule(mixed $rule): string
    {
        if (is_object($rule)) {
            return get_class($rule);
        }

        return trim((string) $rule);
    }

    /**
     * @return array<string, array{type: string, format?: string, pattern?: string}>
     */
    private function defaultRuleTypes(): array
    {
        return [
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'numeric' => ['type' => 'number'],
            'array' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            'file' => ['type' => 'string', 'format' => 'binary'],
            'image' => ['type' => 'string', 'format' => 'binary'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'date_format' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'ip' => ['type' => 'string', 'format' => 'ipv4'],
            'ipv4' => ['type' => 'string', 'format' => 'ipv4'],
            'ipv6' => ['type' => 'string', 'format' => 'ipv6'],
            'json' => ['type' => 'string', 'format' => 'json'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            'alpha' => ['type' => 'string', 'pattern' => '^[a-zA-Z]+$'],
            'alpha_num' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9]+$'],
            'alpha_dash' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]+$'],
            'regex' => ['type' => 'string'],
            'digits' => ['type' => 'string', 'pattern' => '^[0-9]+$'],
            'digits_between' => ['type' => 'string'],
        ];
    }
}
