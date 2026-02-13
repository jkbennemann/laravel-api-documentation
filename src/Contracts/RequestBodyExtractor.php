<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Contracts;

use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;

interface RequestBodyExtractor
{
    public function extract(AnalysisContext $ctx): ?SchemaResult;
}
