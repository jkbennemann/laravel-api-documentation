<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Support\Collection;

/**
 * Validates documentation accuracy against captured responses
 */
class DocumentationValidator
{
    public function __construct(
        private CapturedResponseRepository $repository,
        private ResponseAnalyzer $responseAnalyzer,
        private RouteComposition $routeComposition
    ) {}

    /**
     * Validate all routes
     */
    public function validateAll(): array
    {
        $routes = $this->getAllRoutes();
        $results = [];

        foreach ($routes as $route) {
            // Handle action which might be an array [Controller, method]
            $action = $route['action'];
            if (is_array($action)) {
                $action = $action[1] ?? null;
            }

            $validation = $this->validateRoute($route['uri'], $route['method'], $route['controller'], $action);

            if ($validation) {
                $results[] = $validation;
            }
        }

        return $this->buildValidationReport($results);
    }

    /**
     * Validate specific route
     */
    public function validateRoute(
        string $uri,
        ?string $method = null,
        ?string $controller = null,
        ?string $action = null
    ): ?array {
        // If only URI provided, try to find the route
        if (!$method) {
            $route = $this->findRoute($uri);
            if (!$route) {
                return null;
            }
            $method = $route['method'];
            $controller = $route['controller'];
            $action = $route['action'];
        }

        // Get captured response
        $captured = $this->repository->getForRoute($uri, $method);

        if (!$captured) {
            return [
                'uri' => $uri,
                'method' => $method,
                'accuracy' => 0,
                'issues' => 'No captured response found',
                'status' => 'missing_capture',
            ];
        }

        // Get static analysis result
        if (!$controller || !$action) {
            return [
                'uri' => $uri,
                'method' => $method,
                'accuracy' => 100, // Captured data exists, that's all we can validate
                'issues' => null,
                'status' => 'captured_only',
            ];
        }

        $static = $this->responseAnalyzer->analyzeControllerMethod($controller, $action);

        // Compare static vs captured
        $comparison = $this->compareSchemas($static, $captured);

        return [
            'uri' => $uri,
            'method' => $method,
            'accuracy' => $comparison['accuracy'],
            'issues' => $comparison['issues'],
            'status' => $comparison['accuracy'] >= 95 ? 'passed' : ($comparison['accuracy'] >= 80 ? 'warning' : 'failed'),
            'details' => $comparison['details'] ?? null,
        ];
    }

    /**
     * Compare static analysis schema with captured response
     */
    private function compareSchemas(?array $static, array $captured): array
    {
        if (!$static) {
            return [
                'accuracy' => 100, // If we have captured data, use it
                'issues' => null,
            ];
        }

        $issues = [];
        $details = [];
        $totalChecks = 0;
        $passedChecks = 0;

        // For each captured status code, compare with static analysis
        foreach ($captured as $statusCode => $capturedResponse) {
            $capturedSchema = $capturedResponse['schema'] ?? null;

            if (!$capturedSchema) {
                continue;
            }

            // Compare schema structures
            $schemaComparison = $this->compareSchemaStructure($static, $capturedSchema);
            $totalChecks += $schemaComparison['total'];
            $passedChecks += $schemaComparison['passed'];

            if (!empty($schemaComparison['differences'])) {
                $issues[] = "Status {$statusCode}: " . count($schemaComparison['differences']) . ' schema difference(s)';
                $details[$statusCode] = $schemaComparison['differences'];
            }
        }

        $accuracy = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 2) : 100;

        return [
            'accuracy' => $accuracy,
            'issues' => !empty($issues) ? implode('; ', $issues) : null,
            'details' => !empty($details) ? $details : null,
        ];
    }

    /**
     * Compare two schema structures
     */
    private function compareSchemaStructure(array $expected, array $actual, string $path = ''): array
    {
        $differences = [];
        $total = 0;
        $passed = 0;

        // Compare type
        if (isset($expected['type']) && isset($actual['type'])) {
            $total++;
            if ($expected['type'] === $actual['type']) {
                $passed++;
            } else {
                $differences[] = "{$path}type: expected '{$expected['type']}', got '{$actual['type']}'";
            }
        }

        // Compare properties for objects
        if (isset($expected['properties']) && isset($actual['properties'])) {
            $expectedProps = array_keys($expected['properties']);
            $actualProps = array_keys($actual['properties']);

            // Check for missing properties in actual
            $missing = array_diff($expectedProps, $actualProps);
            if (!empty($missing)) {
                $differences[] = "{$path}missing properties: " . implode(', ', $missing);
            }

            // Check for extra properties in actual
            $extra = array_diff($actualProps, $expectedProps);
            if (!empty($extra)) {
                $differences[] = "{$path}extra properties: " . implode(', ', $extra);
            }

            // Recursively compare common properties
            $common = array_intersect($expectedProps, $actualProps);
            foreach ($common as $prop) {
                $propPath = $path ? "{$path}.{$prop}" : $prop;
                $propComparison = $this->compareSchemaStructure(
                    $expected['properties'][$prop],
                    $actual['properties'][$prop],
                    $propPath
                );
                $total += $propComparison['total'];
                $passed += $propComparison['passed'];
                $differences = array_merge($differences, $propComparison['differences']);
            }
        }

        // Compare array items
        if (isset($expected['items']) && isset($actual['items'])) {
            $itemPath = $path ? "{$path}[]" : '[]';
            $itemComparison = $this->compareSchemaStructure($expected['items'], $actual['items'], $itemPath);
            $total += $itemComparison['total'];
            $passed += $itemComparison['passed'];
            $differences = array_merge($differences, $itemComparison['differences']);
        }

        return [
            'total' => $total,
            'passed' => $passed,
            'differences' => $differences,
        ];
    }

    /**
     * Build validation report
     */
    private function buildValidationReport(array $results): array
    {
        $totalAccuracy = 0;
        $count = count($results);

        $commonIssues = [];

        foreach ($results as $result) {
            $totalAccuracy += $result['accuracy'];

            if ($result['issues']) {
                $issue = $this->categorizeIssue($result['issues']);
                $commonIssues[$issue] = ($commonIssues[$issue] ?? 0) + 1;
            }
        }

        $overallAccuracy = $count > 0 ? round($totalAccuracy / $count, 2) : 100;

        return [
            'overall_accuracy' => $overallAccuracy,
            'total_routes' => $count,
            'routes' => $results,
            'common_issues' => $commonIssues,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Categorize issue for summary
     */
    private function categorizeIssue(string $issue): string
    {
        if (str_contains($issue, 'missing properties')) {
            return 'Missing properties in response';
        } elseif (str_contains($issue, 'extra properties')) {
            return 'Extra properties in response';
        } elseif (str_contains($issue, 'type:')) {
            return 'Type mismatch';
        } elseif (str_contains($issue, 'No captured response')) {
            return 'Missing captured response';
        }

        return 'Other';
    }

    /**
     * Get all routes from composition
     */
    private function getAllRoutes(): array
    {
        $routesData = $this->routeComposition->process();

        return collect($routesData)->map(function ($route) {
            return [
                'uri' => $route['route'] ?? '',
                'method' => $route['method'] ?? 'GET',
                'controller' => $route['controller'] ?? null,
                'action' => $route['action'] ?? null,
            ];
        })->toArray();
    }

    /**
     * Find route by URI
     */
    private function findRoute(string $uri): ?array
    {
        $routes = $this->getAllRoutes();

        foreach ($routes as $route) {
            if ($route['uri'] === $uri) {
                return $route;
            }
        }

        return null;
    }
}
