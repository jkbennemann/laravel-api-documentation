<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Schema;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class ExampleGenerator
{
    /**
     * Recursively walk a schema tree and fill in synthetic examples
     * on every leaf property that doesn't already have one.
     */
    public function generate(SchemaObject $schema): SchemaObject
    {
        // $ref schemas get examples on the referenced component, not the reference
        if ($schema->ref !== null) {
            return $schema;
        }

        // For objects, recurse into properties (don't set example on the object itself)
        if ($schema->type === 'object' && ! empty($schema->properties)) {
            $modified = false;
            $newProperties = [];

            foreach ($schema->properties as $name => $propSchema) {
                $generated = $this->generateForSchema($propSchema, $name);
                if ($generated !== $propSchema) {
                    $modified = true;
                }
                $newProperties[$name] = $generated;
            }

            if ($modified) {
                $clone = clone $schema;
                $clone->properties = $newProperties;

                return $clone;
            }

            return $schema;
        }

        // For arrays, recurse into items and build a single-item example
        if ($schema->type === 'array' && $schema->items !== null) {
            $generatedItems = $this->generateForSchema($schema->items, null);

            if ($schema->example === null && $generatedItems->example !== null) {
                $clone = clone $schema;
                $clone->items = $generatedItems;
                $clone->example = [$generatedItems->example];

                return $clone;
            }

            if ($generatedItems !== $schema->items) {
                $clone = clone $schema;
                $clone->items = $generatedItems;

                return $clone;
            }

            return $schema;
        }

        // For scalar leaves, generate an example
        return $this->generateForSchema($schema, null);
    }

    /**
     * Generate an example for a single schema node, recursing for nested types.
     */
    private function generateForSchema(SchemaObject $schema, ?string $fieldName): SchemaObject
    {
        if ($schema->ref !== null) {
            return $schema;
        }

        // Recurse into objects and arrays first
        if ($schema->type === 'object' && ! empty($schema->properties)) {
            return $this->generate($schema);
        }

        if ($schema->type === 'array' && $schema->items !== null) {
            return $this->generate($schema);
        }

        // Already has an example — skip
        if ($schema->example !== null) {
            return $schema;
        }

        $example = $this->generateValue($schema, $fieldName);

        if ($example === null) {
            return $schema;
        }

        $clone = clone $schema;
        $clone->example = $example;

        return $clone;
    }

    /**
     * Produce a synthetic example value from schema metadata.
     *
     * Priority: enum → default → format → field name → constraints → type fallback
     */
    private function generateValue(SchemaObject $schema, ?string $fieldName): mixed
    {
        // 1. Enum — pick first value
        if (! empty($schema->enum)) {
            return $schema->enum[0];
        }

        // 2. Default value
        if ($schema->default !== null) {
            return $schema->default;
        }

        // 3. Format-based example
        if ($schema->format !== null) {
            $fromFormat = $this->generateFromFormat($schema->type ?? 'string', $schema->format);
            if ($fromFormat !== null) {
                return $fromFormat;
            }
        }

        // 4. Field name heuristics
        if ($fieldName !== null) {
            $fromName = $this->guessFromFieldName($fieldName, $schema->type);
            if ($fromName !== null) {
                return $fromName;
            }
        }

        // 5. Numeric constraints
        if (in_array($schema->type, ['integer', 'number'], true)) {
            if ($schema->minimum !== null && $schema->maximum !== null) {
                $mid = ($schema->minimum + $schema->maximum) / 2;

                return $schema->type === 'integer' ? (int) round($mid) : round($mid, 2);
            }
            if ($schema->minimum !== null) {
                return $schema->type === 'integer' ? (int) $schema->minimum : $schema->minimum;
            }
            if ($schema->maximum !== null) {
                return $schema->type === 'integer' ? (int) $schema->maximum : $schema->maximum;
            }
        }

        // 6. Type-based fallback
        return $this->generateFromType($schema->type);
    }

    private function guessFromFieldName(string $name, ?string $type): mixed
    {
        $lower = strtolower($name);

        // Email
        if (str_contains($lower, 'email')) {
            return 'user@example.com';
        }

        // Password
        if (str_contains($lower, 'password')) {
            return 'password123';
        }

        // Token
        if (str_contains($lower, 'token')) {
            return 'abc123def456';
        }

        // Phone
        if (str_contains($lower, 'phone')) {
            return '+1-555-555-5555';
        }

        // URL/link/href
        if (str_contains($lower, 'url') || str_contains($lower, 'link') || str_contains($lower, 'href')) {
            return 'https://example.com';
        }

        // Image/avatar/photo
        if (str_contains($lower, 'image') || str_contains($lower, 'avatar') || str_contains($lower, 'photo')) {
            return 'https://example.com/image.jpg';
        }

        // Slug
        if (str_contains($lower, 'slug')) {
            return 'example-slug';
        }

        // Color/colour
        if (str_contains($lower, 'color') || str_contains($lower, 'colour')) {
            return '#3B82F6';
        }

        // Date/time fields (before generic name matches)
        if (str_ends_with($lower, '_at') || str_ends_with($lower, 'datetime')) {
            return '2025-01-15T10:30:00Z';
        }
        if (str_contains($lower, 'date')) {
            return '2025-01-15';
        }

        // Address fields (before count/quantity to avoid "country" matching "count")
        if (str_contains($lower, 'address')) {
            return '123 Main St';
        }
        if (str_contains($lower, 'city')) {
            return 'New York';
        }
        if (str_contains($lower, 'country')) {
            return 'US';
        }
        if (str_contains($lower, 'zip') || str_contains($lower, 'postal')) {
            return '10001';
        }

        // Amount/price/cost
        if (str_contains($lower, 'amount') || str_contains($lower, 'price') || str_contains($lower, 'cost')) {
            return 99.99;
        }

        // Latitude/longitude
        if (str_contains($lower, 'latitude') || $lower === 'lat') {
            return 40.7128;
        }
        if (str_contains($lower, 'longitude') || str_contains($lower, 'lng') || $lower === 'lon') {
            return -74.006;
        }

        // Age
        if ($lower === 'age') {
            return 25;
        }

        // Pagination
        if ($lower === 'page') {
            return 1;
        }
        if ($lower === 'per_page' || $lower === 'limit') {
            return 15;
        }

        // Count/quantity
        if (str_contains($lower, 'count') || str_contains($lower, 'quantity')) {
            return 1;
        }

        // ID fields
        if ($lower === 'id' || str_ends_with($lower, '_id') || str_ends_with($lower, 'id')) {
            return $type === 'string' ? '1' : 1;
        }

        // Status
        if (str_contains($lower, 'status')) {
            return 'active';
        }

        // Type
        if ($lower === 'type') {
            return 'default';
        }

        // Sort/order
        if (str_contains($lower, 'sort') || str_contains($lower, 'order')) {
            return 'asc';
        }

        // Title
        if (str_contains($lower, 'title')) {
            return 'Example title';
        }

        // Name fields
        if (str_contains($lower, 'name')) {
            return 'Example name';
        }

        // Description
        if (str_contains($lower, 'description')) {
            return 'A description';
        }

        return null;
    }

    private function generateFromFormat(string $type, string $format): mixed
    {
        return match ($format) {
            'email' => 'user@example.com',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'date' => '2025-01-15',
            'date-time' => '2025-01-15T10:30:00Z',
            'uri', 'url' => 'https://example.com',
            'ipv4' => '192.168.1.1',
            'ipv6' => '2001:0db8:85a3::8a2e:0370:7334',
            'json' => '{}',
            'binary' => null, // No useful example for binary
            default => null,
        };
    }

    private function generateFromType(?string $type): mixed
    {
        return match ($type) {
            'string' => 'string',
            'integer' => 1,
            'number' => 0.0,
            'boolean' => true,
            default => null,
        };
    }
}
