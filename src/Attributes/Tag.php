<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class Tag
{
    public function __construct(
        public null|string|array $value = null,
        public ?string $description = null,
    ) {}
}
