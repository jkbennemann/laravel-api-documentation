<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

class GrandchildDto
{
    public function __construct(
        public readonly string $label,
        public readonly bool $active,
    ) {}

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'active' => $this->active,
        ];
    }
}
