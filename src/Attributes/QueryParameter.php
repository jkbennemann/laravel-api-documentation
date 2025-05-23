<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
class QueryParameter
{
    public function __construct(
        public string $name,
        public string $description = '',
        public string $type = 'string',
        public ?string $format = null,
        public bool $required = false,
        public mixed $example = null,
        public ?array $enum = null,
    ) {}
}
