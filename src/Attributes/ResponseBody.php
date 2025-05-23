<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ResponseBody
{
    public function __construct(
        public int $statusCode = 200,
        public string $description = '',
        public string $contentType = 'application/json',
        public ?string $dataClass = null,
        public ?array $example = null,
        public bool $isCollection = false,
    ) {}
}
