<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Attributes;

use Attribute;

#[Attribute]
class AdditionalDocumentation
{
    public function __construct(public string $url, public string $description = '') {}
}
