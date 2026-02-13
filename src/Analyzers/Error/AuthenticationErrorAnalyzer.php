<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Error;

use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class AuthenticationErrorAnalyzer implements ResponseExtractor
{
    public function __construct(
        private ?ExceptionHandlerSchemaAnalyzer $handlerAnalyzer = null,
    ) {}

    private const AUTH_MIDDLEWARE = [
        'auth',
        'auth:api',
        'auth:sanctum',
        'auth:web',
        'jwt.auth',
        'jwt.verify',
    ];

    public function extract(AnalysisContext $ctx): array
    {
        $middleware = $ctx->route->middleware;

        $hasAuth = false;
        foreach ($middleware as $mw) {
            // Match auth middleware (including auth:guard patterns)
            if (in_array($mw, self::AUTH_MIDDLEWARE, true) || str_starts_with($mw, 'auth:')) {
                $hasAuth = true;
                break;
            }
        }

        if (! $hasAuth) {
            return [];
        }

        return [
            new ResponseResult(
                statusCode: 401,
                schema: $this->unauthorizedSchema(),
                description: 'Unauthenticated',
                source: 'error:authentication',
            ),
        ];
    }

    private function unauthorizedSchema(): SchemaObject
    {
        $custom = $this->handlerAnalyzer?->getErrorSchema(401);

        return $custom ?? SchemaObject::object(
            properties: [
                'message' => new SchemaObject(
                    type: 'string',
                    example: 'Unauthenticated.',
                ),
            ],
            required: ['message'],
        );
    }
}
