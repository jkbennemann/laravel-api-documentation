<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ResponseHeader
{
    public function __construct(
        public string $name,
        public string $description = '',
        public string $type = 'string',
        public ?string $format = null,
        public ?string $example = null,
        public bool $required = false,
    ) {}
}
