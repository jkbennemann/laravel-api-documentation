<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class RequestBody
{
    public function __construct(
        public string $description = '',
        public string $contentType = 'application/json',
        public bool $required = true,
        public ?string $dataClass = null,
        public ?array $example = null,
    ) {}
}
