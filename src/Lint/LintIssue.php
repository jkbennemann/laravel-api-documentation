<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Lint;

final class LintIssue
{
    public function __construct(
        public readonly string $severity,
        public readonly string $location,
        public readonly string $message,
    ) {}
}
