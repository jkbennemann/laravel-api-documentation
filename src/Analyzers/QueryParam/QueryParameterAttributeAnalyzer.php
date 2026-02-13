<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam;

use JkBennemann\LaravelApiDocumentation\Attributes\QueryParameter;
use JkBennemann\LaravelApiDocumentation\Contracts\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class QueryParameterAttributeAnalyzer implements QueryParameterExtractor
{
    public function extract(AnalysisContext $ctx): array
    {
        $attributes = $ctx->getAttributes(QueryParameter::class);
        if (empty($attributes)) {
            return [];
        }

        $params = [];

        foreach ($attributes as $attr) {
            $schema = new SchemaObject(
                type: $attr->type,
                format: $attr->format,
                enum: $attr->enum,
            );

            $params[] = ParameterResult::query(
                name: $attr->name,
                schema: $schema,
                required: $attr->required,
                description: $attr->description ?: null,
                example: $attr->example,
            );
        }

        return $params;
    }
}
