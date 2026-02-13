<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Diff;

final class DiffEntry
{
    public function __construct(
        public readonly string $type,
        public readonly string $location,
        public readonly string $message,
    ) {}
}
