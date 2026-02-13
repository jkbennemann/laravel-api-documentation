<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Merge;

use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;

class ResultMerger
{
    private string $strategy;

    public function __construct(string $strategy = 'static_first')
    {
        $this->strategy = $strategy;
    }

    /**
     * Merge request body results from multiple analyzers.
     * Returns the highest-priority non-null result.
     */
    public function mergeRequestBodies(?SchemaResult $static, ?SchemaResult $runtime): ?SchemaResult
    {
        if ($this->strategy === 'captured_first') {
            return $runtime ?? $static;
        }

        return $static ?? $runtime;
    }

    /**
     * Merge response results by status code.
     * Higher-priority source fills in, lower-priority provides examples.
     *
     * @param  array<int, ResponseResult>  $static
     * @param  array<int, ResponseResult>  $runtime
     * @return array<int, ResponseResult>
     */
    public function mergeResponses(array $static, array $runtime): array
    {
        if ($this->strategy === 'captured_first') {
            $primary = $runtime;
            $secondary = $static;
        } else {
            $primary = $static;
            $secondary = $runtime;
        }

        $merged = $primary;

        foreach ($secondary as $statusCode => $result) {
            if (! isset($merged[$statusCode])) {
                $merged[$statusCode] = $result;
            } else {
                // Merge examples from secondary into primary
                $merged[$statusCode] = $this->mergeResponseResult($merged[$statusCode], $result);
            }
        }

        ksort($merged);

        return $merged;
    }

    /**
     * Merge query parameters by name.
     *
     * @param  ParameterResult[]  $static
     * @param  ParameterResult[]  $runtime
     * @return ParameterResult[]
     */
    public function mergeQueryParameters(array $static, array $runtime): array
    {
        $merged = [];

        // Index by name
        foreach ($static as $param) {
            $merged[$param->name] = $param;
        }

        foreach ($runtime as $param) {
            if (! isset($merged[$param->name])) {
                $merged[$param->name] = $param;
            } elseif ($param->example !== null && $merged[$param->name]->example === null) {
                // Add example from runtime to static parameter
                $existing = $merged[$param->name];
                $merged[$param->name] = new ParameterResult(
                    name: $existing->name,
                    in: $existing->in,
                    schema: $existing->schema,
                    required: $existing->required,
                    description: $existing->description,
                    example: $param->example,
                    deprecated: $existing->deprecated,
                    source: $existing->source,
                );
            }
        }

        return array_values($merged);
    }

    private function mergeResponseResult(ResponseResult $primary, ResponseResult $secondary): ResponseResult
    {
        // Keep primary schema and description, add examples from secondary
        $examples = array_merge($secondary->examples, $primary->examples);

        // If primary has no schema but secondary does, use secondary
        $schema = $primary->schema ?? $secondary->schema;

        return new ResponseResult(
            statusCode: $primary->statusCode,
            schema: $schema,
            description: $primary->description ?: $secondary->description,
            contentType: $primary->contentType,
            headers: array_merge($secondary->headers, $primary->headers),
            examples: $examples,
            source: $primary->source,
            isCollection: $primary->isCollection,
        );
    }
}
