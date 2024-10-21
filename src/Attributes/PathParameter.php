<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class PathParameter
{
    public function __construct(
        public string $name,
        public string $description = '',
        public string $type = 'string',
        public ?string $format = null,
        public bool $required = true,
        public mixed $example = null,
    ) {}
}
