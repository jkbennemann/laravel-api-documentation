<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

class ChildDto
{
    public function __construct(
        public readonly string $name,
        public readonly int $value,
        public readonly GrandchildDto $detail,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'detail' => $this->detail->toArray(),
        ];
    }
}
