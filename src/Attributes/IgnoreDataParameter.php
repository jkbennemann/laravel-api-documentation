<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class IgnoreDataParameter
{
    public function __construct(
        public string $parameters,
    ) {}
}
