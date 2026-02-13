<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Contracts;

use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;

interface ResponseExtractor
{
    /**
     * @return ResponseResult[]
     */
    public function extract(AnalysisContext $ctx): array;
}
