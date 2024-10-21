<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class Parameter
{
    public function __construct(
        public string $name,
        public bool $required = false,
        public string $type = 'string',
        public ?string $format = null,
        public string $description = '',
        public bool $deprecated = false,
        public mixed $example = null,
    ) {}
}
