<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

final class Reference implements \JsonSerializable
{
    public function __construct(
        public readonly string $ref,
    ) {}

    public static function schema(string $name): self
    {
        return new self('#/components/schemas/'.$name);
    }

    public static function response(string $name): self
    {
        return new self('#/components/responses/'.$name);
    }

    public static function parameter(string $name): self
    {
        return new self('#/components/parameters/'.$name);
    }

    public static function requestBody(string $name): self
    {
        return new self('#/components/requestBodies/'.$name);
    }

    public static function securityScheme(string $name): self
    {
        return new self('#/components/securitySchemes/'.$name);
    }

    public function name(): string
    {
        return basename($this->ref);
    }

    public function category(): string
    {
        $parts = explode('/', $this->ref);

        return $parts[2] ?? 'schemas';
    }

    public function jsonSerialize(): mixed
    {
        return ['$ref' => $this->ref];
    }
}
