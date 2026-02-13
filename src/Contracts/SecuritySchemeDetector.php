<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Contracts;

use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;

interface SecuritySchemeDetector
{
    /**
     * @return array{name: string, scheme: array<string, mixed>, scopes?: string[]}|null
     */
    public function detect(AnalysisContext $ctx): ?array;
}
