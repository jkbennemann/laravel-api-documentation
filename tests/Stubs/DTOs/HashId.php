<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

use Spatie\LaravelData\Data;

class HashId extends Data
{
    public function __construct(
        public readonly string $hashId
    ) {}
}
