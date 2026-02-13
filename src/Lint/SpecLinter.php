<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Lint;

class SpecLinter
{
    /** @var LintIssue[] */
    private array $issues = [];

    /** @var array<string, int> Coverage counters */
    private array $coverage = [
        'operations_total' => 0,
        'operations_with_summary' => 0,
        'operations_with_description' => 0,
        'properties_total' => 0,
        'properties_with_examples' => 0,
        'operations_with_error_responses' => 0,
        'operations_needing_error_responses' => 0,
        'request_bodies_total' => 0,
        'request_bodies_documented' => 0,
        'response_bodies_total' => 0,
        'response_bodies_documented' => 0,
    ];

    /**
     * Lint an OpenAPI spec and return issues + coverage.
     *
     * @param  array<string, mixed>  $spec
     * @return array{issues: LintIssue[], coverage: array<string, mixed>, score: int, grade: string}
     */
    public function lint(array $spec): array
    {
        $this->issues = [];
        $this->coverage = array_map(fn () => 0, $this->coverage);

        $this->lintInfo($spec);
        $this->lintPaths($spec);
        $this->lintComponents($spec);
        $this->lintNamingConsistency($spec);

        $coverage = $this->calculateCoveragePercentages();
        $score = $this->calculateScore($coverage);
        $grade = $this->scoreToGrade($score);

        return [
            'issues' => $this->issues,
            'coverage' => $coverage,
            'score' => $score,
            'grade' => $grade,
        ];
    }

    private function lintInfo(array $spec): void
    {
        $info = $spec['info'] ?? [];

        if (empty($info['description'])) {
            $this->addIssue('warning', 'info', 'API description is missing. Add a description to help consumers understand your API.');
        }

        if (empty($spec['servers'])) {
            $this->addIssue('warning', 'servers', 'No servers defined. Consumers won\'t know the base URL.');
        }
    }

    private function lintPaths(array $spec): void
    {
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $location = strtoupper($method).' '.$path;
                $this->coverage['operations_total']++;

                // Summary
                if (! empty($operation['summary'])) {
                    $this->coverage['operations_with_summary']++;
                } else {
                    $this->addIssue('warning', $location, 'Missing summary.');
                }

                // Description
                if (! empty($operation['description'])) {
                    $this->coverage['operations_with_description']++;
                }

                // Request body
                $this->lintRequestBody($operation, $location);

                // Responses
                $this->lintResponses($operation, $location, $method);

                // Operation ID
                if (empty($operation['operationId'])) {
                    $this->addIssue('info', $location, 'Missing operationId. SDK generators need this for method names.');
                }
            }
        }
    }

    private function lintRequestBody(array $operation, string $location): void
    {
        $method = strtolower(explode(' ', $location)[0]);

        if (in_array($method, ['post', 'put', 'patch'], true)) {
            $this->coverage['request_bodies_total']++;

            if (isset($operation['requestBody']['content'])) {
                $this->coverage['request_bodies_documented']++;

                $content = $operation['requestBody']['content']['application/json'] ?? null;
                if ($content !== null) {
                    $this->lintSchemaProperties($content['schema'] ?? [], $location.' requestBody');
                }
            } else {
                $this->addIssue('warning', $location, 'Write endpoint has no request body documented.');
            }
        }
    }

    private function lintResponses(array $operation, string $location, string $method): void
    {
        $responses = $operation['responses'] ?? [];

        if (empty($responses)) {
            $this->addIssue('error', $location, 'No responses defined.');

            return;
        }

        // Check for success response body
        $hasSuccessBody = false;
        foreach ($responses as $status => $response) {
            $statusInt = (int) $status;
            if ($statusInt >= 200 && $statusInt < 300 && $statusInt !== 204) {
                $this->coverage['response_bodies_total']++;
                if (isset($response['content'])) {
                    $hasSuccessBody = true;
                    $this->coverage['response_bodies_documented']++;

                    // Lint response schema properties
                    $content = $response['content']['application/json'] ?? null;
                    if ($content !== null) {
                        $this->lintSchemaProperties($content['schema'] ?? [], $location." response {$status}");
                    }
                }
            }
        }

        if (! $hasSuccessBody && strtolower($method) !== 'delete') {
            $this->addIssue('info', $location, 'Success response has no body schema.');
        }

        // Error response checks
        $hasAuth = ! empty($operation['security']);
        $hasRequestBody = isset($operation['requestBody']);
        $hasPathParams = false;
        foreach ($operation['parameters'] ?? [] as $param) {
            if (($param['in'] ?? '') === 'path') {
                $hasPathParams = true;

                break;
            }
        }

        $needsErrors = $hasAuth || $hasRequestBody || $hasPathParams;
        if ($needsErrors) {
            $this->coverage['operations_needing_error_responses']++;
            $hasErrors = false;

            foreach (array_keys($responses) as $status) {
                if ((int) $status >= 400) {
                    $hasErrors = true;

                    break;
                }
            }

            if ($hasErrors) {
                $this->coverage['operations_with_error_responses']++;
            } else {
                if ($hasAuth && ! isset($responses['401'])) {
                    $this->addIssue('warning', $location, 'Authenticated endpoint missing 401 error response.');
                }
                if ($hasRequestBody && ! isset($responses['422'])) {
                    $this->addIssue('warning', $location, 'Endpoint with request body missing 422 validation error response.');
                }
                if ($hasPathParams && ! isset($responses['404'])) {
                    $this->addIssue('info', $location, 'Endpoint with path parameters missing 404 error response.');
                }
            }
        }
    }

    private function lintSchemaProperties(array $schema, string $location): void
    {
        // Resolve $ref — skip, the component itself will be linted
        if (isset($schema['$ref'])) {
            return;
        }

        if (($schema['type'] ?? null) === 'object' && isset($schema['properties'])) {
            foreach ($schema['properties'] as $name => $prop) {
                if (isset($prop['$ref'])) {
                    continue;
                }

                $this->coverage['properties_total']++;

                if (isset($prop['example'])) {
                    $this->coverage['properties_with_examples']++;
                }

                // Recurse into nested objects
                if (($prop['type'] ?? null) === 'object' && isset($prop['properties'])) {
                    $this->lintSchemaProperties($prop, "{$location}.{$name}");
                }
            }
        }
    }

    private function lintComponents(array $spec): void
    {
        foreach ($spec['components']['schemas'] ?? [] as $name => $schema) {
            $this->lintSchemaProperties($schema, "components.schemas.{$name}");
        }
    }

    private function lintNamingConsistency(array $spec): void
    {
        $fieldNames = [];

        foreach ($spec['paths'] ?? [] as $methods) {
            foreach ($methods as $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                // Collect from request body
                $schema = $operation['requestBody']['content']['application/json']['schema'] ?? [];
                $this->collectFieldNames($schema, $fieldNames);
            }
        }

        // Collect from components
        foreach ($spec['components']['schemas'] ?? [] as $schema) {
            $this->collectFieldNames($schema, $fieldNames);
        }

        // Check for naming style inconsistency
        $camelCount = 0;
        $snakeCount = 0;

        foreach (array_keys($fieldNames) as $name) {
            if (str_contains($name, '_')) {
                $snakeCount++;
            } elseif (preg_match('/[a-z][A-Z]/', $name)) {
                $camelCount++;
            }
        }

        if ($camelCount > 0 && $snakeCount > 0) {
            $this->addIssue(
                'info',
                'naming',
                "Mixed naming conventions detected: {$snakeCount} snake_case and {$camelCount} camelCase fields. Consider standardizing."
            );
        }
    }

    private function collectFieldNames(array $schema, array &$names): void
    {
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $name => $prop) {
                $names[$name] = true;
                if (isset($prop['properties'])) {
                    $this->collectFieldNames($prop, $names);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function calculateCoveragePercentages(): array
    {
        return [
            'summaries' => $this->pct($this->coverage['operations_with_summary'], $this->coverage['operations_total']),
            'descriptions' => $this->pct($this->coverage['operations_with_description'], $this->coverage['operations_total']),
            'examples' => $this->pct($this->coverage['properties_with_examples'], $this->coverage['properties_total']),
            'error_responses' => $this->pct($this->coverage['operations_with_error_responses'], $this->coverage['operations_needing_error_responses']),
            'request_bodies' => $this->pct($this->coverage['request_bodies_documented'], $this->coverage['request_bodies_total']),
            'response_bodies' => $this->pct($this->coverage['response_bodies_documented'], $this->coverage['response_bodies_total']),
            'totals' => $this->coverage,
        ];
    }

    private function pct(int $value, int $total): int
    {
        return $total > 0 ? (int) round(($value / $total) * 100) : 100;
    }

    private function calculateScore(array $coverage): int
    {
        // Weighted average of coverage areas
        $weights = [
            'summaries' => 20,
            'examples' => 25,
            'error_responses' => 20,
            'request_bodies' => 15,
            'response_bodies' => 15,
            'descriptions' => 5,
        ];

        $totalWeight = array_sum($weights);
        $weightedSum = 0;

        foreach ($weights as $key => $weight) {
            $weightedSum += ($coverage[$key] ?? 100) * $weight;
        }

        $baseScore = (int) round($weightedSum / $totalWeight);

        // Penalty for errors
        $errors = count(array_filter($this->issues, fn ($i) => $i->severity === 'error'));
        $penalty = min($errors * 5, 20);

        return max(0, $baseScore - $penalty);
    }

    private function scoreToGrade(int $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'A-',
            $score >= 80 => 'B+',
            $score >= 75 => 'B',
            $score >= 70 => 'B-',
            $score >= 65 => 'C+',
            $score >= 60 => 'C',
            $score >= 50 => 'D',
            default => 'F',
        };
    }

    private function addIssue(string $severity, string $location, string $message): void
    {
        $this->issues[] = new LintIssue($severity, $location, $message);
    }
}
