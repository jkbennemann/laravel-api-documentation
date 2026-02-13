<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Discovery;

use Illuminate\Routing\Router;
use JkBennemann\LaravelApiDocumentation\Attributes\DocumentationFile;
use JkBennemann\LaravelApiDocumentation\Attributes\ExcludeFromDocs;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\RouteInfo;

class RouteDiscovery
{
    private RouteFilter $filter;

    private ControllerReflector $reflector;

    public function __construct(
        private readonly Router $router,
        array $config,
    ) {
        $this->filter = new RouteFilter($config);
        $this->reflector = new ControllerReflector;
    }

    /**
     * Discover all routes and return analysis contexts.
     *
     * @return AnalysisContext[]
     */
    public function discover(?string $documentationFile = null): array
    {
        $contexts = [];

        foreach ($this->router->getRoutes() as $route) {
            if (! $this->filter->shouldInclude($route)) {
                continue;
            }

            $methods = $this->filter->filterMethods($route);
            if (empty($methods)) {
                continue;
            }

            $routeInfo = RouteInfo::fromRoute($route);

            // Check documentation file assignment
            if ($documentationFile !== null) {
                $docFiles = $this->resolveDocumentationFiles($routeInfo);
                if (! in_array($documentationFile, $docFiles, true)) {
                    continue;
                }
            }

            // Extract closure if route uses one
            $closure = $this->extractClosure($route);

            // Build context for each HTTP method
            foreach ($methods as $method) {
                $routeInfoForMethod = new RouteInfo(
                    uri: $routeInfo->uri,
                    methods: [$method],
                    controller: $routeInfo->controller,
                    action: $routeInfo->action,
                    middleware: $routeInfo->middleware,
                    domain: $routeInfo->domain,
                    pathParameters: $routeInfo->pathParameters,
                    name: $routeInfo->name,
                    documentationFiles: $this->resolveDocumentationFiles($routeInfo),
                    pathConstraints: $routeInfo->pathConstraints,
                    bindingFields: $routeInfo->bindingFields,
                );

                $context = $this->reflector->buildContext($routeInfoForMethod, $closure);

                if ($context->hasAttribute(ExcludeFromDocs::class)) {
                    continue;
                }

                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /**
     * Discover routes filtered for a specific domain.
     *
     * @return AnalysisContext[]
     */
    public function discoverForDomain(string $domain): array
    {
        return array_filter(
            $this->discover(),
            function (AnalysisContext $ctx) use ($domain) {
                $routeDomain = $ctx->route->domain;

                if ($routeDomain === null) {
                    return $domain === 'default';
                }

                return $routeDomain === $domain;
            }
        );
    }

    /**
     * Get a single route's context by URI and method for debugging.
     */
    public function discoverRoute(string $uri, string $method = 'GET'): ?AnalysisContext
    {
        foreach ($this->router->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array(strtoupper($method), $route->methods(), true)) {
                $routeInfo = RouteInfo::fromRoute($route);
                $routeInfo = new RouteInfo(
                    uri: $routeInfo->uri,
                    methods: [strtoupper($method)],
                    controller: $routeInfo->controller,
                    action: $routeInfo->action,
                    middleware: $routeInfo->middleware,
                    domain: $routeInfo->domain,
                    pathParameters: $routeInfo->pathParameters,
                    name: $routeInfo->name,
                    pathConstraints: $routeInfo->pathConstraints,
                    bindingFields: $routeInfo->bindingFields,
                );

                $closure = $this->extractClosure($route);
                $context = $this->reflector->buildContext($routeInfo, $closure);

                if ($context->hasAttribute(ExcludeFromDocs::class)) {
                    return null;
                }

                return $context;
            }
        }

        return null;
    }

    /**
     * Extract the closure from a route's action, if it uses one.
     */
    private function extractClosure(\Illuminate\Routing\Route $route): ?\Closure
    {
        $uses = $route->getAction()['uses'] ?? null;

        return $uses instanceof \Closure ? $uses : null;
    }

    /**
     * @return string[]
     */
    private function resolveDocumentationFiles(RouteInfo $route): array
    {
        if ($route->controller === null) {
            return ['default'];
        }

        try {
            $reflectionClass = new \ReflectionClass($route->controller);
            $attrs = $reflectionClass->getAttributes(DocumentationFile::class);

            if (! empty($attrs)) {
                $instance = $attrs[0]->newInstance();
                $value = $instance->value ?? null;
                if ($value !== null) {
                    return is_array($value) ? $value : [$value];
                }
            }

            // Also check method level
            if ($route->action !== null && $reflectionClass->hasMethod($route->action)) {
                $methodAttrs = $reflectionClass->getMethod($route->action)->getAttributes(DocumentationFile::class);
                if (! empty($methodAttrs)) {
                    $instance = $methodAttrs[0]->newInstance();
                    $value = $instance->value ?? null;
                    if ($value !== null) {
                        return is_array($value) ? $value : [$value];
                    }
                }
            }
        } catch (\Throwable) {
            // Skip
        }

        return ['default'];
    }
}
