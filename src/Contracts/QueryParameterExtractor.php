<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Contracts;

use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;

interface QueryParameterExtractor
{
    /**
     * @return ParameterResult[]
     */
    public function extract(AnalysisContext $ctx): array;
}
