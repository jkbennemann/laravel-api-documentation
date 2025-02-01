<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

class Repository
{
    private array $config;

    public function __construct()
    {
        $this->config = config('laravel-api-documentation', []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        data_set($this->config, $key, $value);
    }

    public function has(string $key): bool
    {
        return data_get($this->config, $key) !== null;
    }

    public function all(): array
    {
        return $this->config;
    }
}
