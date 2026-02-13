<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Emission;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Schema\ExampleGenerator;

class SchemaBuilder
{
    private ExampleGenerator $exampleGenerator;

    public function __construct(?ExampleGenerator $exampleGenerator = null)
    {
        $this->exampleGenerator = $exampleGenerator ?? new ExampleGenerator;
    }

    /**
     * Convert a SchemaObject to an OpenAPI-compatible array.
     *
     * @return array<string, mixed>
     */
    public function build(SchemaObject $schema): array
    {
        $schema = $this->exampleGenerator->generate($schema);

        return $schema->jsonSerialize();
    }

    /**
     * Wrap a schema in a media type content block.
     *
     * @return array<string, mixed>
     */
    public function wrapInContent(SchemaObject $schema, string $contentType = 'application/json'): array
    {
        return [
            $contentType => [
                'schema' => $this->build($schema),
            ],
        ];
    }

    /**
     * Wrap with examples.
     *
     * @param  array<string, mixed>  $examples
     * @return array<string, mixed>
     */
    public function wrapInContentWithExamples(
        SchemaObject $schema,
        array $examples = [],
        string $contentType = 'application/json',
    ): array {
        $content = [
            'schema' => $this->build($schema),
        ];

        if (! empty($examples)) {
            if (count($examples) === 1) {
                $content['example'] = reset($examples);
            } else {
                $formatted = [];
                foreach ($examples as $name => $value) {
                    $formatted[$name] = ['value' => $value];
                }
                $content['examples'] = $formatted;
            }
        }

        return [$contentType => $content];
    }
}
