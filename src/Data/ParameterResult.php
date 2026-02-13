<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

final class ParameterResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $in,
        public readonly SchemaObject $schema,
        public readonly bool $required = false,
        public readonly ?string $description = null,
        public readonly mixed $example = null,
        public readonly bool $deprecated = false,
        public readonly ?string $source = null,
    ) {}

    public static function query(
        string $name,
        SchemaObject $schema,
        bool $required = false,
        ?string $description = null,
        mixed $example = null,
    ): self {
        return new self(
            name: $name,
            in: 'query',
            schema: $schema,
            required: $required,
            description: $description,
            example: $example,
        );
    }

    public static function path(
        string $name,
        SchemaObject $schema,
        ?string $description = null,
        mixed $example = null,
    ): self {
        return new self(
            name: $name,
            in: 'path',
            schema: $schema,
            required: true,
            description: $description,
            example: $example,
        );
    }

    public static function header(
        string $name,
        SchemaObject $schema,
        bool $required = false,
        ?string $description = null,
    ): self {
        return new self(
            name: $name,
            in: 'header',
            schema: $schema,
            required: $required,
            description: $description,
        );
    }
}
