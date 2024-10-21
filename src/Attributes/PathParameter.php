<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class PathParameter
{
    public function __construct(
        public string $name,
        public string $description = '',
        public mixed $example = null,
    ) {}
}
