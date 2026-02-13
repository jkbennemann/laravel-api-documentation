<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

class SelfReferencingDto
{
    public function __construct(
        public readonly string $name,
        public readonly ?self $parent,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'parent' => $this->parent?->toArray(),
        ];
    }
}
