<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam;

use JkBennemann\LaravelApiDocumentation\Contracts\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Repository\CapturedResponseRepository;

class RuntimeCaptureQueryAnalyzer implements QueryParameterExtractor
{
    public function __construct(
        private readonly CapturedResponseRepository $repository,
    ) {}

    public function extract(AnalysisContext $ctx): array
    {
        $captured = $this->repository->getForRoute(
            $ctx->route->uri,
            $ctx->route->httpMethod()
        );

        if (empty($captured)) {
            return [];
        }

        $params = [];

        foreach ($captured as $captureData) {
            $request = $captureData['request'] ?? null;
            if ($request === null) {
                continue;
            }

            $querySchema = $request['query_schema'] ?? null;
            if ($querySchema === null) {
                continue;
            }

            $queryParams = $request['query_parameters'] ?? [];

            if (isset($querySchema['properties'])) {
                foreach ($querySchema['properties'] as $name => $propSchema) {
                    if (isset($params[$name])) {
                        continue;
                    }

                    $schema = new SchemaObject(
                        type: $propSchema['type'] ?? 'string',
                        format: $propSchema['format'] ?? null,
                    );

                    $params[$name] = ParameterResult::query(
                        name: $name,
                        schema: $schema,
                        required: false,
                        example: $queryParams[$name] ?? null,
                    );
                }
            }

            break; // Use first capture that has query data
        }

        return array_values($params);
    }
}
