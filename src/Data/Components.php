<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

final class Components implements \JsonSerializable
{
    /**
     * @param  array<string, SchemaObject>  $schemas
     * @param  array<string, array<string, mixed>>  $responses
     * @param  array<string, array<string, mixed>>  $parameters
     * @param  array<string, array<string, mixed>>  $requestBodies
     * @param  array<string, array<string, mixed>>  $securitySchemes
     */
    public function __construct(
        public array $schemas = [],
        public array $responses = [],
        public array $parameters = [],
        public array $requestBodies = [],
        public array $securitySchemes = [],
    ) {}

    public function addSchema(string $name, SchemaObject $schema): Reference
    {
        $this->schemas[$name] = $schema;

        return Reference::schema($name);
    }

    public function addSecurityScheme(string $name, array $scheme): void
    {
        $this->securitySchemes[$name] = $scheme;
    }

    public function hasSchema(string $name): bool
    {
        return isset($this->schemas[$name]);
    }

    public function getSchema(string $name): ?SchemaObject
    {
        return $this->schemas[$name] ?? null;
    }

    public function isEmpty(): bool
    {
        return empty($this->schemas)
            && empty($this->responses)
            && empty($this->parameters)
            && empty($this->requestBodies)
            && empty($this->securitySchemes);
    }

    public function jsonSerialize(): mixed
    {
        $data = [];

        if (! empty($this->schemas)) {
            ksort($this->schemas);
            $data['schemas'] = array_map(
                fn (SchemaObject $s) => $s->jsonSerialize(),
                $this->schemas
            );
        }

        if (! empty($this->responses)) {
            $data['responses'] = $this->responses;
        }

        if (! empty($this->parameters)) {
            $data['parameters'] = $this->parameters;
        }

        if (! empty($this->requestBodies)) {
            $data['requestBodies'] = $this->requestBodies;
        }

        if (! empty($this->securitySchemes)) {
            $data['securitySchemes'] = $this->securitySchemes;
        }

        return $data;
    }
}
