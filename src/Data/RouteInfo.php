<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

use Illuminate\Routing\Route;

final class RouteInfo
{
    /**
     * @param  string[]  $methods
     * @param  string[]  $middleware
     * @param  array<string, string>  $pathParameters
     * @param  string[]  $documentationFiles
     * @param  array<string, string>  $pathConstraints
     * @param  array<string, string>  $bindingFields
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $methods,
        public readonly ?string $controller,
        public readonly ?string $action,
        public readonly array $middleware,
        public readonly ?string $domain,
        public readonly array $pathParameters,
        public readonly ?string $name,
        public readonly array $documentationFiles = ['default'],
        public readonly array $pathConstraints = [],
        public readonly array $bindingFields = [],
    ) {}

    public static function fromRoute(Route $route): self
    {
        $action = $route->getAction();
        $controller = null;
        $method = null;

        if (isset($action['controller'])) {
            $parts = explode('@', $action['controller']);
            $controller = $parts[0] ?? null;
            $method = $parts[1] ?? '__invoke';
        }

        // Extract path parameters from URI
        preg_match_all('/\{(\w+)\??}/', $route->uri(), $matches);
        $pathParams = [];
        foreach ($matches[1] as $param) {
            $pathParams[$param] = 'string';
        }

        // Extract where constraints
        $wheres = property_exists($route, 'wheres') ? $route->wheres : [];

        // Extract binding fields ({user:slug} → ['user' => 'slug'])
        $bindingFields = method_exists($route, 'bindingFields') ? $route->bindingFields() : [];

        return new self(
            uri: $route->uri(),
            methods: $route->methods(),
            controller: $controller,
            action: $method,
            middleware: $route->middleware(),
            domain: $route->getDomain(),
            pathParameters: $pathParams,
            name: $route->getName(),
            pathConstraints: $wheres,
            bindingFields: $bindingFields,
        );
    }

    public function httpMethod(): string
    {
        foreach ($this->methods as $method) {
            if ($method !== 'HEAD') {
                return $method;
            }
        }

        return $this->methods[0] ?? 'GET';
    }

    public function isClosureRoute(): bool
    {
        return $this->controller === null;
    }

    public function controllerClass(): ?string
    {
        return $this->controller;
    }

    public function actionMethod(): ?string
    {
        return $this->action;
    }
}
