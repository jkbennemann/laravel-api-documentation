<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Contracts;

use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;

interface OperationTransformer
{
    /**
     * Transform an OpenAPI operation array before emission.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function transform(array $operation, AnalysisContext $ctx): array;
}
