<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
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
        public mixed $parameters = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $items = null,
        public bool $nullable = false,
        public ?string $resource = null,
    ) {}
}
