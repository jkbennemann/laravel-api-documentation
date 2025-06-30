<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class DocumentationFile
{
    public function __construct(
        string|array $value
    ) {}
}
