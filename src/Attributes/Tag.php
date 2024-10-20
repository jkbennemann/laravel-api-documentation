<?php

declare(strict_types=1);

namespace Bennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class Tag
{
    public ?array $value = null;

    public function __construct(null|string|array $value = null)
    {
        if (empty($value)) {
            return;
        }

        if (is_string($value)) {
            $value = explode(',', $value);
            $value = array_map('trim', $value);
        }

        $this->value = array_filter($value);
    }
}
