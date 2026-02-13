<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

final class SchemaResult
{
    /**
     * @param  array<string, mixed>  $examples
     */
    public function __construct(
        public readonly SchemaObject $schema,
        public readonly ?string $description = null,
        public readonly ?string $contentType = 'application/json',
        public readonly array $examples = [],
        public readonly ?string $source = null,
        public readonly bool $required = true,
    ) {}

    public function withDescription(string $description): self
    {
        return new self(
            schema: $this->schema,
            description: $description,
            contentType: $this->contentType,
            examples: $this->examples,
            source: $this->source,
            required: $this->required,
        );
    }

    public function withExamples(array $examples): self
    {
        return new self(
            schema: $this->schema,
            description: $this->description,
            contentType: $this->contentType,
            examples: array_merge($this->examples, $examples),
            source: $this->source,
            required: $this->required,
        );
    }
}
