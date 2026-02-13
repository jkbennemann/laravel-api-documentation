<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

final class HandlerAnalysisResult
{
    /**
     * @param  array<string, SchemaObject>  $baseProperties  Always-present fields (timestamp, message, path, etc.)
     * @param  string[]  $baseRequired  Always-required field names
     * @param  array<string, SchemaObject>  $conditionalProperties  Conditionally present (errors, details)
     * @param  array<class-string, int>  $statusCodeMapping  Exception class → HTTP status code
     * @param  array<int, string>  $statusMessages  Status code → custom message example
     */
    public function __construct(
        public readonly array $baseProperties = [],
        public readonly array $baseRequired = [],
        public readonly array $conditionalProperties = [],
        public readonly array $statusCodeMapping = [],
        public readonly array $statusMessages = [],
    ) {}
}
