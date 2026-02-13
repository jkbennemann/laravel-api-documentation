<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Merge;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class ExampleMerger
{
    /**
     * Merge runtime-captured examples into a statically-analyzed schema
     * without overwriting type information.
     */
    public function mergeExamplesIntoSchema(SchemaObject $schema, mixed $example): SchemaObject
    {
        if ($example === null) {
            return $schema;
        }

        // For $ref schemas, don't add examples (they belong to the referenced schema)
        if ($schema->ref !== null) {
            return $schema;
        }

        // For scalar types, set the example directly
        if (in_array($schema->type, ['string', 'integer', 'number', 'boolean'], true)) {
            return $this->withExample($schema, $example);
        }

        // For arrays, merge example into items schema
        if ($schema->type === 'array' && is_array($example) && $schema->items !== null) {
            $firstItem = $example[0] ?? null;
            if ($firstItem !== null) {
                $mergedItems = $this->mergeExamplesIntoSchema($schema->items, $firstItem);
                $clone = clone $schema;
                $clone->items = $mergedItems;
                $clone->example = $example;

                return $clone;
            }

            return $this->withExample($schema, $example);
        }

        // For objects, recursively merge examples into properties
        if ($schema->type === 'object' && is_array($example) && ! empty($schema->properties)) {
            $mergedProperties = [];
            foreach ($schema->properties as $name => $propSchema) {
                if (array_key_exists($name, $example)) {
                    $mergedProperties[$name] = $this->mergeExamplesIntoSchema($propSchema, $example[$name]);
                } else {
                    $mergedProperties[$name] = $propSchema;
                }
            }

            $clone = clone $schema;
            $clone->properties = $mergedProperties;

            return $clone;
        }

        return $this->withExample($schema, $example);
    }

    /**
     * Merge multiple examples into a schema, selecting the best one.
     *
     * @param  array<string, mixed>  $examples
     */
    public function mergeMultipleExamples(SchemaObject $schema, array $examples): SchemaObject
    {
        if (empty($examples)) {
            return $schema;
        }

        // Use the first example for deep merging
        $firstExample = reset($examples);

        return $this->mergeExamplesIntoSchema($schema, $firstExample);
    }

    private function withExample(SchemaObject $schema, mixed $example): SchemaObject
    {
        // Only set example if the schema doesn't already have one
        if ($schema->example !== null) {
            return $schema;
        }

        $clone = clone $schema;
        $clone->example = $example;

        return $clone;
    }
}
