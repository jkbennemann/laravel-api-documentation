<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

final class ResponseResult
{
    /**
     * @param  array<string, array{description: string, schema?: SchemaObject, example?: mixed}>  $headers
     * @param  array<string, mixed>  $examples
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly ?SchemaObject $schema = null,
        public readonly string $description = '',
        public readonly string $contentType = 'application/json',
        public readonly array $headers = [],
        public readonly array $examples = [],
        public readonly ?string $source = null,
        public readonly bool $isCollection = false,
    ) {}

    public function withDescription(string $description): self
    {
        return new self(
            statusCode: $this->statusCode,
            schema: $this->schema,
            description: $description,
            contentType: $this->contentType,
            headers: $this->headers,
            examples: $this->examples,
            source: $this->source,
            isCollection: $this->isCollection,
        );
    }

    public function withSchema(SchemaObject $schema): self
    {
        return new self(
            statusCode: $this->statusCode,
            schema: $schema,
            description: $this->description,
            contentType: $this->contentType,
            headers: $this->headers,
            examples: $this->examples,
            source: $this->source,
            isCollection: $this->isCollection,
        );
    }

    public static function noContent(int $statusCode = 204): self
    {
        return new self(
            statusCode: $statusCode,
            description: 'No Content',
        );
    }
}
