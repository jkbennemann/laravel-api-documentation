<?php

declare(strict_types=1);

namespace Bennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class Summary
{
    public function __construct(public string $value = '')
    {
    }
}
