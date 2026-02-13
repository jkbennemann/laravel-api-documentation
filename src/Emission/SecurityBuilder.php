<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Emission;

use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;

class SecurityBuilder
{
    /** @var array<string, array<string, mixed>> Collected security schemes */
    private array $schemes = [];

    public function __construct(
        private readonly SchemaRegistry $registry,
    ) {}

    /**
     * Register a security scheme detected during analysis.
     *
     * @param  array<string, mixed>  $scheme
     */
    public function addScheme(string $name, array $scheme): void
    {
        if (! isset($this->schemes[$name])) {
            $this->schemes[$name] = $scheme;
            $this->registry->addSecurityScheme($name, $scheme);
        }
    }

    /**
     * Build the security requirement for an operation.
     *
     * @param  array{name: string, scheme: array<string, mixed>, scopes?: string[]}|null  $detected
     * @return array<int, array<string, string[]>>|null
     */
    public function buildOperationSecurity(?array $detected): ?array
    {
        if ($detected === null) {
            return null;
        }

        $this->addScheme($detected['name'], $detected['scheme']);
        $scopes = $detected['scopes'] ?? [];

        return [[$detected['name'] => $scopes]];
    }

    /**
     * Build the global security section.
     *
     * @return array<int, array<string, string[]>>
     */
    public function buildGlobalSecurity(): array
    {
        $security = [];
        foreach (array_keys($this->schemes) as $name) {
            $security[] = [$name => []];
        }

        return $security;
    }
}
