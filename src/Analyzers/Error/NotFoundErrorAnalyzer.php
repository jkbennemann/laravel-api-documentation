<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Error;

use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class NotFoundErrorAnalyzer implements ResponseExtractor
{
    public function __construct(
        private ?ExceptionHandlerSchemaAnalyzer $handlerAnalyzer = null,
    ) {}

    public function extract(AnalysisContext $ctx): array
    {
        if (! $this->shouldHave404($ctx)) {
            return [];
        }

        return [
            new ResponseResult(
                statusCode: 404,
                schema: $this->notFoundSchema(),
                description: 'Not Found',
                source: 'error:not_found',
            ),
        ];
    }

    private function shouldHave404(AnalysisContext $ctx): bool
    {
        // Any route with path parameters should have a 404 —
        // if the URL has {id}, the resource might not exist
        if (! empty($ctx->route->pathParameters)) {
            return true;
        }

        // Also check for Eloquent model binding (covers edge cases)
        if ($ctx->reflectionMethod === null) {
            return false;
        }

        foreach ($ctx->reflectionMethod->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();
            try {
                if (is_subclass_of($className, \Illuminate\Database\Eloquent\Model::class)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    private function notFoundSchema(): SchemaObject
    {
        $custom = $this->handlerAnalyzer?->getErrorSchema(404);

        return $custom ?? SchemaObject::object(
            properties: [
                'message' => new SchemaObject(
                    type: 'string',
                    example: 'No query results for model.',
                ),
            ],
            required: ['message'],
        );
    }
}
