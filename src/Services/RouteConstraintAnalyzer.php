<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Throwable;

class RouteConstraintAnalyzer
{
    /**
     * Extract parameter constraints from route definitions
     */
    public function analyzeRouteConstraints(Route $route): array
    {
        $constraints = [];

        try {
            $uri = $route->uri();
            $parameters = $this->extractParametersFromUri($uri);
            $wheres = $route->wheres;

            foreach ($parameters as $parameter) {
                $constraint = [
                    'name' => $parameter,
                    'required' => ! $this->isOptionalParameter($parameter, $uri),
                    'type' => 'string', // Default type
                    'description' => "Path parameter: {$parameter}",
                ];

                // Apply route constraints (where clauses)
                if (isset($wheres[$parameter])) {
                    $pattern = $wheres[$parameter];
                    $constraintInfo = $this->analyzeConstraintPattern($pattern);
                    $constraint = array_merge($constraint, $constraintInfo);
                }

                $constraints[$parameter] = $constraint;
            }

            return $constraints;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Extract parameter names from route URI
     */
    private function extractParametersFromUri(string $uri): array
    {
        preg_match_all('/\{([^}]+)\}/', $uri, $matches);

        $parameters = [];
        foreach ($matches[1] as $match) {
            // Remove optional marker (?)
            $paramName = rtrim($match, '?');
            $parameters[] = $paramName;
        }

        return array_unique($parameters);
    }

    /**
     * Check if parameter is optional in the URI
     */
    private function isOptionalParameter(string $parameter, string $uri): bool
    {
        return str_contains($uri, "{{$parameter}?}");
    }

    /**
     * Analyze constraint pattern and extract OpenAPI information
     */
    private function analyzeConstraintPattern(string $pattern): array
    {
        $constraint = [];

        // Remove regex delimiters if present
        $cleanPattern = trim($pattern, '/');

        // Analyze common patterns
        if ($this->isNumericPattern($cleanPattern)) {
            $constraint['type'] = 'integer';
            $constraint['description'] = 'Must be a numeric value.';
            $constraint['pattern'] = $cleanPattern;
        } elseif ($this->isUuidPattern($cleanPattern)) {
            $constraint['type'] = 'string';
            $constraint['format'] = 'uuid';
            $constraint['description'] = 'Must be a valid UUID.';
            $constraint['example'] = '123e4567-e89b-12d3-a456-426614174000';
            $constraint['pattern'] = $cleanPattern;
        } elseif ($this->isHashIdPattern($cleanPattern)) {
            $constraint['type'] = 'string';
            $constraint['format'] = 'hash-id';
            $constraint['description'] = 'Must be a valid hash ID.';
            $constraint['example'] = 'abc123XYZ';
            $constraint['pattern'] = $cleanPattern;
        } elseif ($this->isAlphaPattern($cleanPattern)) {
            $constraint['type'] = 'string';
            $constraint['description'] = 'Must contain only alphabetic characters.';
            $constraint['pattern'] = $cleanPattern;
        } elseif ($this->isAlphaNumericPattern($cleanPattern)) {
            $constraint['type'] = 'string';
            $constraint['description'] = 'Must contain only alphanumeric characters.';
            $constraint['pattern'] = $cleanPattern;
        } elseif ($this->isEnumPattern($cleanPattern)) {
            $enumInfo = $this->extractEnumFromPattern($cleanPattern);
            $constraint = array_merge($constraint, $enumInfo);
        } else {
            // Generic pattern
            $constraint['type'] = 'string';
            $constraint['pattern'] = $cleanPattern;
            $constraint['description'] = "Must match pattern: {$cleanPattern}";
        }

        return $constraint;
    }

    /**
     * Check if pattern matches numeric values
     */
    private function isNumericPattern(string $pattern): bool
    {
        $numericPatterns = [
            '^\d+$',
            '^[0-9]+$',
            '^\d{1,}$',
            '^[0-9]{1,}$',
            '^\d*$',
            '^[0-9]*$',
        ];

        return in_array($pattern, $numericPatterns) ||
               preg_match('/^\^\\\d\+\$$/', $pattern) ||
               preg_match('/^\^\[0-9\]\+\$$/', $pattern);
    }

    /**
     * Check if pattern matches UUID format
     */
    private function isUuidPattern(string $pattern): bool
    {
        $uuidPatterns = [
            '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
            '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$',
            '^[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12}$',
        ];

        return in_array(strtolower($pattern), array_map('strtolower', $uuidPatterns)) ||
               str_contains(strtolower($pattern), 'uuid') ||
               preg_match('/\[a-f0-9\]\{8\}.*\[a-f0-9\]\{4\}.*\[a-f0-9\]\{4\}.*\[a-f0-9\]\{4\}.*\[a-f0-9\]\{12\}/', strtolower($pattern));
    }

    /**
     * Check if pattern matches hash ID format
     */
    private function isHashIdPattern(string $pattern): bool
    {
        return str_contains(strtolower($pattern), 'hashid') ||
               preg_match('/^\^?\[a-zA-Z0-9\]\+\$?$/', $pattern) ||
               preg_match('/^\^?\[a-zA-Z0-9\]\{8,\}\$?$/', $pattern);
    }

    /**
     * Check if pattern matches alphabetic characters only
     */
    private function isAlphaPattern(string $pattern): bool
    {
        $alphaPatterns = [
            '^[a-zA-Z]+$',
            '^[A-Za-z]+$',
            '^[a-z]+$',
            '^[A-Z]+$',
        ];

        return in_array($pattern, $alphaPatterns);
    }

    /**
     * Check if pattern matches alphanumeric characters
     */
    private function isAlphaNumericPattern(string $pattern): bool
    {
        $alphaNumPatterns = [
            '^[a-zA-Z0-9]+$',
            '^[A-Za-z0-9]+$',
            '^[a-z0-9]+$',
            '^[A-Z0-9]+$',
        ];

        return in_array($pattern, $alphaNumPatterns);
    }

    /**
     * Check if pattern represents an enum (list of specific values)
     */
    private function isEnumPattern(string $pattern): bool
    {
        return str_contains($pattern, '|') &&
               str_starts_with($pattern, '(') &&
               str_ends_with($pattern, ')');
    }

    /**
     * Extract enum values from pattern like (value1|value2|value3)
     */
    private function extractEnumFromPattern(string $pattern): array
    {
        // Remove parentheses and anchors
        $cleanPattern = trim($pattern, '^$()');
        $values = explode('|', $cleanPattern);

        return [
            'type' => 'string',
            'enum' => $values,
            'description' => 'Must be one of: '.implode(', ', $values).'.',
            'example' => $values[0] ?? null,
        ];
    }

    /**
     * Analyze all routes and extract parameter constraints
     */
    public function analyzeAllRouteConstraints(): array
    {
        $allConstraints = [];

        try {
            $routes = RouteFacade::getRoutes();

            foreach ($routes as $route) {
                $routeName = $route->getName() ?? $route->uri();
                $constraints = $this->analyzeRouteConstraints($route);

                if (! empty($constraints)) {
                    $allConstraints[$routeName] = $constraints;
                }
            }

            return $allConstraints;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get constraint information for a specific route parameter
     */
    public function getParameterConstraint(Route $route, string $parameterName): ?array
    {
        $constraints = $this->analyzeRouteConstraints($route);

        return $constraints[$parameterName] ?? null;
    }

    /**
     * Check if route has constraints
     */
    public function hasConstraints(Route $route): bool
    {
        return ! empty($route->wheres) || $this->hasParametersInUri($route->uri());
    }

    /**
     * Check if URI contains parameters
     */
    private function hasParametersInUri(string $uri): bool
    {
        return str_contains($uri, '{') && str_contains($uri, '}');
    }

    /**
     * Generate OpenAPI parameter documentation for route
     */
    public function generateParameterDocumentation(Route $route): array
    {
        $constraints = $this->analyzeRouteConstraints($route);
        $documentation = [];

        foreach ($constraints as $paramName => $constraint) {
            $param = [
                'name' => $paramName,
                'in' => 'path',
                'required' => $constraint['required'],
                'schema' => [
                    'type' => $constraint['type'],
                ],
                'description' => $constraint['description'],
            ];

            if (isset($constraint['format'])) {
                $param['schema']['format'] = $constraint['format'];
            }

            if (isset($constraint['pattern'])) {
                $param['schema']['pattern'] = $constraint['pattern'];
            }

            if (isset($constraint['enum'])) {
                $param['schema']['enum'] = $constraint['enum'];
            }

            if (isset($constraint['example'])) {
                $param['example'] = $constraint['example'];
            }

            $documentation[] = $param;
        }

        return $documentation;
    }
}
