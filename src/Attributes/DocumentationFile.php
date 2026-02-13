<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class DocumentationFile
{
    public string|array $value;

    public function __construct(
        string|array $value
    ) {
        $this->value = $value;
    }
}
