<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam;

use JkBennemann\LaravelApiDocumentation\Contracts\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Schema\PhpDocParser;

class PhpDocQueryParameterAnalyzer implements QueryParameterExtractor
{
    private const QUERY_METHODS = ['GET', 'HEAD'];

    public function __construct(private readonly PhpDocParser $phpDocParser) {}

    /**
     * @return ParameterResult[]
     */
    public function extract(AnalysisContext $ctx): array
    {
        if (! in_array($ctx->route->httpMethod(), self::QUERY_METHODS, true)) {
            return [];
        }

        if ($ctx->reflectionMethod === null) {
            return [];
        }

        $paramTags = $this->phpDocParser->getParamTags($ctx->reflectionMethod);
        if (empty($paramTags)) {
            return [];
        }

        // Get actual method parameter names (type-hinted params) to exclude them
        $methodParamNames = [];
        foreach ($ctx->reflectionMethod->getParameters() as $param) {
            $methodParamNames[] = $param->getName();
        }

        // Also exclude path parameters
        $pathParamNames = array_keys($ctx->route->pathParameters);

        $parameters = [];

        foreach ($paramTags as $name => $info) {
            // Skip params that match type-hinted method parameters
            if (in_array($name, $methodParamNames, true)) {
                continue;
            }

            // Skip params that match path parameters
            if (in_array($name, $pathParamNames, true)) {
                continue;
            }

            $parameters[] = ParameterResult::query(
                name: $name,
                schema: $info['schema'],
                required: false,
                description: $info['description'],
            );
        }

        return $parameters;
    }
}
