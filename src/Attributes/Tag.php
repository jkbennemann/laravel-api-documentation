<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class Tag
{
    public null|array|string $value = null;

    public function __construct(null|string|array $value = null) {}
}
