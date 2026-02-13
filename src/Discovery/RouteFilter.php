<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Discovery;

use Illuminate\Routing\Route;

class RouteFilter
{
    /** @var string[] */
    private array $excludedPatterns;

    /** @var string[] */
    private array $excludedMethods;

    private bool $includeVendorRoutes;

    private bool $includeClosureRoutes;

    public function __construct(array $config)
    {
        $this->excludedPatterns = $config['excluded_routes'] ?? [];
        $this->excludedMethods = array_map('strtoupper', $config['excluded_methods'] ?? ['HEAD', 'OPTIONS']);
        $this->includeVendorRoutes = $config['include_vendor_routes'] ?? false;
        $this->includeClosureRoutes = $config['include_closure_routes'] ?? false;
    }

    public function shouldInclude(Route $route): bool
    {
        if (! $this->includeClosureRoutes && $this->isClosureRoute($route)) {
            return false;
        }

        if (! $this->includeVendorRoutes && $this->isVendorRoute($route)) {
            return false;
        }

        if ($this->isExcludedByPattern($route)) {
            return false;
        }

        return true;
    }

    /**
     * Filter HTTP methods for this route, removing excluded ones.
     *
     * @return string[]
     */
    public function filterMethods(Route $route): array
    {
        return array_values(array_filter(
            $route->methods(),
            fn (string $method) => ! in_array(strtoupper($method), $this->excludedMethods, true)
        ));
    }

    private function isClosureRoute(Route $route): bool
    {
        $action = $route->getAction();

        return ! isset($action['controller']);
    }

    private function isVendorRoute(Route $route): bool
    {
        $action = $route->getAction();

        if (! isset($action['controller'])) {
            return false;
        }

        $controller = explode('@', $action['controller'])[0];

        try {
            $reflection = new \ReflectionClass($controller);
            $filename = $reflection->getFileName();

            if ($filename === false) {
                return false;
            }

            return str_contains($filename, '/vendor/');
        } catch (\ReflectionException) {
            return false;
        }
    }

    private function isExcludedByPattern(Route $route): bool
    {
        $uri = $route->uri();
        $name = $route->getName();

        // Check for inclusion-only patterns (prefixed with !)
        $inclusionPatterns = array_filter($this->excludedPatterns, fn ($p) => str_starts_with($p, '!'));
        if (! empty($inclusionPatterns)) {
            foreach ($inclusionPatterns as $pattern) {
                $pattern = substr($pattern, 1); // Remove !
                if ($this->matchesPattern($uri, $pattern) || ($name && $this->matchesPattern($name, $pattern))) {
                    return false; // Included explicitly
                }
            }

            return true; // Not matching any inclusion pattern = excluded
        }

        // Check exclusion patterns
        $exclusionPatterns = array_filter($this->excludedPatterns, fn ($p) => ! str_starts_with($p, '!'));
        foreach ($exclusionPatterns as $pattern) {
            if ($this->matchesPattern($uri, $pattern) || ($name && $this->matchesPattern($name, $pattern))) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        if (! str_contains($pattern, '*')) {
            return $value === $pattern;
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

        return (bool) preg_match($regex, $value);
    }
}
