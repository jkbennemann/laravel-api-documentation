<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Schema;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class SchemaFingerprinter
{
    /**
     * Generate a content-based hash for a schema.
     * Two schemas with the same structure produce the same fingerprint,
     * regardless of description differences or property ordering.
     */
    public function fingerprint(SchemaObject $schema): string
    {
        $normalized = $this->normalize($schema);

        return md5(json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Normalize a schema for consistent fingerprinting.
     * Strips descriptions, examples, and sorts properties alphabetically.
     */
    private function normalize(SchemaObject $schema): array
    {
        $data = [];

        if ($schema->ref !== null) {
            return ['$ref' => $schema->ref->ref];
        }

        if ($schema->type !== null) {
            $data['type'] = $schema->type;
        }
        if ($schema->format !== null) {
            $data['format'] = $schema->format;
        }
        if ($schema->nullable) {
            $data['nullable'] = true;
        }
        if ($schema->enum !== null) {
            $sorted = $schema->enum;
            sort($sorted);
            $data['enum'] = $sorted;
        }
        if ($schema->pattern !== null) {
            $data['pattern'] = $schema->pattern;
        }

        // Normalize items
        if ($schema->items !== null) {
            $data['items'] = $this->normalize($schema->items);
        }

        // Normalize properties (sorted by key)
        if ($schema->properties !== null && $schema->properties !== []) {
            $props = [];
            $keys = array_keys($schema->properties);
            sort($keys);
            foreach ($keys as $key) {
                $props[$key] = $this->normalize($schema->properties[$key]);
            }
            $data['properties'] = $props;
        }

        if ($schema->required !== null && $schema->required !== []) {
            $req = $schema->required;
            sort($req);
            $data['required'] = $req;
        }

        // Normalize composition
        if ($schema->oneOf !== null) {
            $data['oneOf'] = array_map(fn (SchemaObject $s) => $this->normalize($s), $schema->oneOf);
        }
        if ($schema->allOf !== null) {
            $data['allOf'] = array_map(fn (SchemaObject $s) => $this->normalize($s), $schema->allOf);
        }
        if ($schema->anyOf !== null) {
            $data['anyOf'] = array_map(fn (SchemaObject $s) => $this->normalize($s), $schema->anyOf);
        }

        // Constraints
        if ($schema->minLength !== null) {
            $data['minLength'] = $schema->minLength;
        }
        if ($schema->maxLength !== null) {
            $data['maxLength'] = $schema->maxLength;
        }
        if ($schema->minimum !== null) {
            $data['minimum'] = $schema->minimum;
        }
        if ($schema->maximum !== null) {
            $data['maximum'] = $schema->maximum;
        }
        if ($schema->minItems !== null) {
            $data['minItems'] = $schema->minItems;
        }
        if ($schema->maxItems !== null) {
            $data['maxItems'] = $schema->maxItems;
        }

        return $data;
    }
}
