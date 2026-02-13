<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Error;

use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class RateLimitErrorAnalyzer implements ResponseExtractor
{
    public function __construct(
        private ?ExceptionHandlerSchemaAnalyzer $handlerAnalyzer = null,
    ) {}

    /**
     * @return ResponseResult[]
     */
    public function extract(AnalysisContext $ctx): array
    {
        $throttle = $this->detectThrottle($ctx);

        if ($throttle === null) {
            return [];
        }

        $results = [];

        // Add 429 error response
        $customSchema = $this->handlerAnalyzer?->getErrorSchema(429);
        $results[] = new ResponseResult(
            statusCode: 429,
            description: 'Too many requests. Please try again later.',
            schema: $customSchema ?? SchemaObject::object([
                'message' => SchemaObject::string(),
            ]),
            headers: [
                'Retry-After' => [
                    'description' => 'Number of seconds until the rate limit resets.',
                    'schema' => ['type' => 'integer'],
                    'example' => 60,
                ],
                'X-RateLimit-Limit' => [
                    'description' => 'Maximum number of requests allowed per period.',
                    'schema' => ['type' => 'integer'],
                    'example' => $throttle['limit'],
                ],
                'X-RateLimit-Remaining' => [
                    'description' => 'Number of requests remaining in the current period.',
                    'schema' => ['type' => 'integer'],
                    'example' => 0,
                ],
            ],
        );

        return $results;
    }

    /**
     * Detect throttle middleware and extract limit.
     *
     * @return array{limit: int, period: ?int}|null
     */
    private function detectThrottle(AnalysisContext $ctx): ?array
    {
        foreach ($ctx->route->middleware as $middleware) {
            // Match 'throttle:60,1' or 'throttle:api' or just 'throttle'
            if ($middleware === 'throttle' || str_starts_with($middleware, 'throttle:')) {
                $parts = explode(':', $middleware, 2);
                $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

                $limit = 60; // Default Laravel throttle limit
                if (! empty($params[0]) && is_numeric($params[0])) {
                    $limit = (int) $params[0];
                }

                $period = null;
                if (! empty($params[1]) && is_numeric($params[1])) {
                    $period = (int) $params[1];
                }

                return ['limit' => $limit, 'period' => $period];
            }
        }

        return null;
    }
}
