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

        // Schemas with properties — recurse into each property (don't set example on the object itself)
        if (! empty($schema->properties)) {
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

        // Composition schemas — recurse into sub-schemas
        if ($schema->oneOf !== null || $schema->anyOf !== null || $schema->allOf !== null) {
            return $this->generateForComposition($schema);
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

        // Recurse into schemas with properties (regardless of type)
        if (! empty($schema->properties)) {
            return $this->generate($schema);
        }

        if ($schema->type === 'array' && $schema->items !== null) {
            return $this->generate($schema);
        }

        // Recurse into composition schemas
        if ($schema->oneOf !== null || $schema->anyOf !== null || $schema->allOf !== null) {
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
     * Recurse into oneOf/anyOf/allOf sub-schemas to fill in examples.
     */
    private function generateForComposition(SchemaObject $schema): SchemaObject
    {
        $modified = false;
        $clone = clone $schema;

        foreach (['oneOf', 'anyOf', 'allOf'] as $key) {
            if ($schema->{$key} === null) {
                continue;
            }

            $newSchemas = [];
            foreach ($schema->{$key} as $subSchema) {
                $generated = $this->generate($subSchema);
                if ($generated !== $subSchema) {
                    $modified = true;
                }
                $newSchemas[] = $generated;
            }
            $clone->{$key} = $newSchemas;
        }

        return $modified ? $clone : $schema;
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

        // 6. String constraint heuristics (maxLength gives a reasonable-length example)
        if ($schema->type === 'string' && $schema->maxLength !== null && $schema->maxLength <= 10) {
            return 'abc';
        }

        // 7. Type-based fallback
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

        // Token / secret / key (auth-related)
        if (str_contains($lower, 'token') || str_contains($lower, 'secret') || $lower === 'api_key') {
            return 'abc123def456';
        }

        // Phone
        if (str_contains($lower, 'phone') || str_contains($lower, 'mobile') || str_contains($lower, 'fax')) {
            return '+1-555-555-5555';
        }

        // URL/link/href/website/homepage
        if (str_contains($lower, 'url') || str_contains($lower, 'link') || str_contains($lower, 'href')
            || str_contains($lower, 'website') || str_contains($lower, 'homepage')) {
            return 'https://example.com';
        }

        // Image/avatar/photo/logo/icon/thumbnail
        if (str_contains($lower, 'image') || str_contains($lower, 'avatar') || str_contains($lower, 'photo')
            || str_contains($lower, 'logo') || str_contains($lower, 'icon') || str_contains($lower, 'thumbnail')) {
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
        if (str_contains($lower, 'date') || str_ends_with($lower, '_on')) {
            return '2025-01-15';
        }

        // Person name fields (before generic 'name' match and before 'first'/'last')
        if (str_contains($lower, 'first_name') || str_contains($lower, 'firstname') || $lower === 'given_name') {
            return 'John';
        }
        if (str_contains($lower, 'last_name') || str_contains($lower, 'lastname')
            || $lower === 'surname' || $lower === 'family_name') {
            return 'Doe';
        }
        if ($lower === 'full_name' || $lower === 'fullname' || $lower === 'display_name') {
            return 'John Doe';
        }
        if ($lower === 'username' || $lower === 'user_name' || $lower === 'login') {
            return 'johndoe';
        }
        if ($lower === 'nickname') {
            return 'Johnny';
        }

        // Address fields (before count/quantity to avoid "country" matching "count")
        if (str_contains($lower, 'address') || $lower === 'line1' || $lower === 'street') {
            return '123 Main St';
        }
        if ($lower === 'line2') {
            return 'Suite 100';
        }
        if ($lower === 'line3' || $lower === 'line4') {
            return '';
        }
        if (str_contains($lower, 'city')) {
            return 'New York';
        }
        if (str_contains($lower, 'state') || str_contains($lower, 'province') || str_contains($lower, 'region')) {
            return 'NY';
        }
        if (str_contains($lower, 'country')) {
            return 'US';
        }
        if (str_contains($lower, 'zip') || str_contains($lower, 'postal')) {
            return '10001';
        }

        // Company/organization
        if (str_contains($lower, 'company') || str_contains($lower, 'organization') || str_contains($lower, 'organisation')) {
            return 'Acme Inc.';
        }

        // Currency
        if ($lower === 'currency' || $lower === 'currency_code') {
            return 'USD';
        }
        if ($lower === 'locale' || $lower === 'language' || $lower === 'lang') {
            return 'en';
        }

        // Amount/price/cost/fee/total/balance/rate
        if (str_contains($lower, 'amount') || str_contains($lower, 'price') || str_contains($lower, 'cost')
            || str_contains($lower, 'fee') || str_contains($lower, 'balance') || str_contains($lower, 'rate')
            || str_contains($lower, 'subtotal') || str_contains($lower, 'discount')) {
            return $type === 'integer' ? 100 : 99.99;
        }

        // Percentage/ratio
        if (str_contains($lower, 'percent') || str_contains($lower, 'ratio')) {
            return $type === 'integer' ? 50 : 0.5;
        }

        // Weight/size/width/height/length/depth/duration
        if (str_contains($lower, 'weight') || str_contains($lower, 'width') || str_contains($lower, 'height')
            || str_contains($lower, 'length') || str_contains($lower, 'depth') || str_contains($lower, 'size')) {
            return $type === 'integer' ? 100 : 10.5;
        }
        if (str_contains($lower, 'duration') || str_contains($lower, 'timeout') || str_contains($lower, 'interval')) {
            return 30;
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
        if ($lower === 'page' || $lower === 'current_page') {
            return 1;
        }
        if ($lower === 'per_page' || $lower === 'limit' || $lower === 'page_size') {
            return 15;
        }
        if ($lower === 'total' || $lower === 'total_count' || $lower === 'total_items') {
            return 100;
        }
        if ($lower === 'last_page' || $lower === 'total_pages') {
            return 10;
        }
        if ($lower === 'from') {
            return $type === 'integer' ? 1 : null;
        }
        if ($lower === 'to') {
            return $type === 'integer' ? 15 : null;
        }

        // Count/quantity/number
        if (str_contains($lower, 'count') || str_contains($lower, 'quantity') || $lower === 'qty') {
            return 1;
        }

        // Priority/position/rank/level
        if (str_contains($lower, 'priority') || str_contains($lower, 'position') || str_contains($lower, 'rank')
            || str_contains($lower, 'level') || $lower === 'order' || $lower === 'sort_order') {
            return 1;
        }

        // Version
        if ($lower === 'version') {
            return '1.0.0';
        }

        // Path (filesystem or URL path)
        if ($lower === 'path') {
            return '/api/resource';
        }

        // Content/body/text/message/note/comment/summary
        if ($lower === 'body' || $lower === 'content' || $lower === 'text' || $lower === 'message'
            || $lower === 'note' || $lower === 'comment' || $lower === 'summary' || $lower === 'bio'
            || $lower === 'excerpt' || $lower === 'reason') {
            return 'Example text content';
        }

        // ID fields
        if ($lower === 'id' || str_ends_with($lower, '_id') || str_ends_with($lower, 'id')) {
            return $type === 'string' ? '1' : 1;
        }

        // Status
        if (str_contains($lower, 'status')) {
            return 'active';
        }

        // Type/kind/category
        if ($lower === 'type' || $lower === 'kind' || $lower === 'category') {
            return 'default';
        }

        // Method (HTTP or payment)
        if ($lower === 'method') {
            return 'GET';
        }

        // Format/mime_type
        if ($lower === 'format' || $lower === 'mime_type' || $lower === 'content_type') {
            return 'application/json';
        }

        // Sort/order direction
        if (str_contains($lower, 'sort') || $lower === 'direction') {
            return 'asc';
        }

        // Title/subject/label/headline
        if (str_contains($lower, 'title') || str_contains($lower, 'subject') || str_contains($lower, 'headline')) {
            return 'Example title';
        }

        // Label/tag
        if ($lower === 'label' || $lower === 'tag') {
            return 'example-label';
        }

        // Name fields (generic, after specific name matches)
        if (str_contains($lower, 'name')) {
            return 'Example name';
        }

        // Description
        if (str_contains($lower, 'description')) {
            return 'A detailed description of the resource';
        }

        // Value (generic)
        if ($lower === 'value' || $lower === 'data' || $lower === 'result') {
            return 'string';
        }

        // Boolean-like field names
        if (str_starts_with($lower, 'is_') || str_starts_with($lower, 'has_') || str_starts_with($lower, 'can_')
            || str_starts_with($lower, 'should_') || str_starts_with($lower, 'allow')
            || $lower === 'active' || $lower === 'enabled' || $lower === 'visible' || $lower === 'published') {
            return true;
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
            'time' => '10:30:00',
            'uri', 'url' => 'https://example.com',
            'hostname' => 'example.com',
            'ipv4' => '192.168.1.1',
            'ipv6' => '2001:0db8:85a3::8a2e:0370:7334',
            'password' => 'password123',
            'byte' => 'U3dhZ2dlciByb2Nrcw==',
            'json' => '{}',
            'binary' => null,
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
