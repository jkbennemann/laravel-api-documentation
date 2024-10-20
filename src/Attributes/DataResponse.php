<?php

declare(strict_types=1);

namespace Bennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class DataResponse
{
    public function __construct(public int $status, public string $description = '', public null|string|array $resource = [], public array $headers = [])
    {
    }
}
