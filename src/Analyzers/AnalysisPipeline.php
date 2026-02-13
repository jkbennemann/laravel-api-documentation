<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers;

use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class AnalysisPipeline
{
    public function __construct(
        private readonly PluginRegistry $registry,
    ) {}

    /**
     * Run all request body extractors. First non-null result wins.
     */
    public function extractRequestBody(AnalysisContext $ctx): ?SchemaResult
    {
        foreach ($this->registry->getRequestExtractors() as $extractor) {
            try {
                $result = $extractor->extract($ctx);
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                $this->logAnalyzerError($extractor, $e);
            }
        }

        return null;
    }

    /**
     * Run all response extractors and merge results by status code.
     * Higher-priority extractors' results take precedence for same status codes.
     *
     * @return array<int, ResponseResult>
     */
    public function extractResponses(AnalysisContext $ctx): array
    {
        /** @var array<int, ResponseResult> */
        $merged = [];

        foreach ($this->registry->getResponseExtractors() as $extractor) {
            try {
                $results = $extractor->extract($ctx);
                foreach ($results as $result) {
                    if (! isset($merged[$result->statusCode])) {
                        $merged[$result->statusCode] = $result;
                    } elseif ($merged[$result->statusCode]->schema === null && $result->schema !== null) {
                        // Replace a schema-less response with one that has a schema
                        $merged[$result->statusCode] = $result;
                    }
                }
            } catch (\Throwable $e) {
                $this->logAnalyzerError($extractor, $e);
            }
        }

        ksort($merged);

        return $merged;
    }

    /**
     * Run all query parameter extractors and merge results.
     * Parameters from higher-priority extractors override lower ones by name.
     *
     * @return ParameterResult[]
     */
    public function extractQueryParameters(AnalysisContext $ctx): array
    {
        /** @var array<string, ParameterResult> */
        $merged = [];

        foreach ($this->registry->getQueryExtractors() as $extractor) {
            try {
                $results = $extractor->extract($ctx);
                foreach ($results as $result) {
                    if (! isset($merged[$result->name])) {
                        $merged[$result->name] = $result;
                    }
                }
            } catch (\Throwable $e) {
                $this->logAnalyzerError($extractor, $e);
            }
        }

        return array_values($merged);
    }

    /**
     * Run all security scheme detectors. Returns first match.
     *
     * @return array{name: string, scheme: array<string, mixed>, scopes?: string[]}|null
     */
    public function detectSecurity(AnalysisContext $ctx): ?array
    {
        foreach ($this->registry->getSecurityDetectors() as $detector) {
            try {
                $result = $detector->detect($ctx);
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                $this->logAnalyzerError($detector, $e);
            }
        }

        return null;
    }

    /**
     * Apply all operation transformers in sequence.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function transformOperation(array $operation, AnalysisContext $ctx): array
    {
        foreach ($this->registry->getOperationTransformers() as $transformer) {
            try {
                $operation = $transformer->transform($operation, $ctx);
            } catch (\Throwable $e) {
                $this->logAnalyzerError($transformer, $e);
            }
        }

        return $operation;
    }

    private function logAnalyzerError(object $analyzer, \Throwable $e): void
    {
        if (function_exists('logger')) {
            logger()->warning('API Documentation analyzer error in '.get_class($analyzer).': '.$e->getMessage());
        }
    }
}
