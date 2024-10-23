<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
use JkBennemann\LaravelApiDocumentation\Attributes\AdditionalDocumentation;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use JkBennemann\LaravelApiDocumentation\Attributes\PathParameter;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class RouteComposition
{
    private RouteCollectionInterface $routes;

    private bool $includeVendorRoutes;

    private array $excludedRoutes;

    private array $excludedMethods;

    public function __construct(protected Router $router, private readonly Repository $configuration)
    {
        $this->routes = $router->getRoutes();
        $this->includeVendorRoutes = $this->configuration->get('api-documentation.include_vendor_routes', false);
        $this->excludedRoutes = $this->configuration->get('api-documentation.excluded_routes', []);
        $this->excludedMethods = $this->configuration->get('api-documentation.excluded_methods', []);
    }

    public function process(): array
    {
        $all = [];

        /** @var Route $route */
        foreach ($this->routes as $route) {
            $httpMethod = $route->methods()[0];
            $uri = $route->uri();

            $middleswares = $this->getMiddlewares($route);
            [$controller, $action] = $this->getControllerData($route);

            if ($controller === null || $action === null) {
                continue;
            }

            $isVendorClass = $this->isVendorClass($controller);
            $urlParams = $this->extractPlaceholders($uri);
            $description = null;
            $summary = null;
            $tags = [];
            $url = null;
            $additionalDescription = null;
            $responses = [];
            $availableRules = [];

            if (! $this->includeVendorRoutes && $isVendorClass) {
                continue;
            }
            foreach ($this->excludedRoutes as $excludedRoute) {
                if ($this->matchUri($uri, $excludedRoute)) {
                    continue 2;
                }
            }
            foreach ($this->excludedMethods as $excludedMethod) {
                if ($httpMethod === strtoupper($excludedMethod)) {
                    continue 2;
                }
            }

            try {
                $actionMethod = new ReflectionMethod($controller, $action);
                $attributes = $actionMethod->getAttributes();
            } catch (Throwable) {
                continue;
            }

            $routeParams = [];

            foreach ($attributes as $attr) {
                if ($attr->getName() === Description::class) {
                    $description = $attr->getArguments()[0] ?? null;
                }
                if ($attr->getName() === Summary::class) {
                    $summary = $attr->getArguments()[0] ?? null;
                }
                if ($attr->getName() === AdditionalDocumentation::class) {
                    $url = $attr->getArguments()['url'] ?? null;
                    $additionalDescription = $attr->getArguments()['description'] ?? null;
                }
                if ($attr->getName() === Tag::class) {
                    $tagsValue = $attr->getArguments()[0] ?? null;
                    if (empty($tagsValue)) {
                        $tags = null;
                    }

                    if (is_string($tagsValue)) {
                        $value = explode(',', $tagsValue);
                        $tags = array_map('trim', $value);
                        $tags = array_filter($tags);
                    } elseif (is_array($tagsValue)) {
                        $tags = $tagsValue;
                    }
                }

                if ($attr->getName() === DataResponse::class) {
                    $status = $attr->getArguments()['status'] ?? $attr->getArguments()[0];
                    $responses[$status] = [
                        'description' => $attr->getArguments()['description'] ?? $attr->getArguments()[1] ?? '',
                        'resource' => $attr->getArguments()['resource'] ?? $attr->getArguments()[2] ?? '',
                        'headers' => $attr->getArguments()['headers'] ?? $attr->getArguments()[3] ?? [],
                    ];
                }

                if ($attr->getName() === PathParameter::class) {
                    $name = $attr->getArguments()['name'] ?? $attr->getArguments()[0];
                    $routeParams[$name] = [
                        'description' => $attr->getArguments()['description'] ?? $attr->getArguments()[1] ?? '',
                        'type' => $attr->getArguments()['type'] ?? $attr->getArguments()[2] ?? 'string',
                        'format' => $attr->getArguments()['format'] ?? $attr->getArguments()[3] ?? null,
                        'required' => $attr->getArguments()['required'] ?? $attr->getArguments()[4] ?? true,
                        'example' => null,
                    ];

                    if ($routeParams[$name]['type'] === 'int') {
                        $routeParams[$name]['type'] = 'integer';
                    }

                    if ($value = $attr->getArguments()['example'] ?? $attr->getArguments()[5] ?? null) {
                        $routeParams[$name]['example'] = [
                            'type' => $routeParams[$name]['type'],
                            'value' => null,
                            'format' => $routeParams[$name]['format'],
                        ];
                        $routeParams[$name]['example']['type'] = $routeParams[$name]['type'];
                        $routeParams[$name]['example']['value'] = $value;

                        if ($routeParams[$name]['format'] !== null) {
                            $routeParams[$name]['example']['format'] = $routeParams[$name]['format'];
                        }
                    }
                }
            }

            foreach ($actionMethod->getParameters() as $parameter) {
                $parameterType = $parameter->getType();
                if ($parameterType === null) {
                    continue;
                }
                if (! $parameterType->isBuiltin()) {
                    try {
                        $classInstance = new ReflectionClass($parameterType->getName());
                        $classInstance = $classInstance->newInstanceWithoutConstructor();
                    } catch (Throwable) {
                        $classInstance = app($parameterType->getName());
                    }

                    if ($classInstance instanceof Request) {
                        //rules
                        $rulesExist = method_exists($classInstance, 'rules');
                        if ($rulesExist) {
                            $actionMethod = new ReflectionMethod($classInstance, 'rules');
                            $attributes = $actionMethod->getAttributes();

                            $parser = new ValidationRuleParser([]);
                            try {
                                $rules = app()->call([$classInstance, 'rules']);
                            } catch (Throwable) {
                                continue;
                            }
                            $availableRules = $parser->explode($rules)->rules ?? [];
                            ksort($availableRules);

                            $availableRules = RuleParser::parse($rules);

                            //enhance rules with additional parameter data
                            foreach ($availableRules as $key => $value) {
                                $attribute = $this->getRuleAttribute($key, $attributes);
                                if ($attribute) {
                                    $availableRules[$key]['description'] = $attribute->getArguments()['description'] ?? $value['description'];
                                    $availableRules[$key]['required'] = $attribute->getArguments()['required'] ?? $availableRules[$key]['required'];
                                    $availableRules[$key]['deprecated'] = $attribute->getArguments()['deprecated'] ?? $availableRules[$key]['deprecated'];
                                    $availableRules[$key]['type'] = $attribute->getArguments()['type'] ?? $availableRules[$key]['type'];
                                    $availableRules[$key]['format'] = $attribute->getArguments()['type'] ?? $availableRules[$key]['format'];
                                }
                            }
                        }
                    }
                }
            }

            $all[] = [
                'method' => $httpMethod,
                'uri' => $uri,
                'summary' => $summary,
                'description' => $description,
                'middlewares' => $middleswares,
                'is_vendor' => $isVendorClass,
                'request_parameters' => $routeParams,
                'parameters' => $availableRules,
                'tags' => $tags,
                'documentation' => $url ? [
                    'url' => $url,
                    'description' => $additionalDescription,
                ] : null,
                'responses' => $responses,
            ];
        }

        return $all;
    }

    private function getMiddlewares(Route $route): array
    {
        $middlewares = $route->getAction('middleware') ?? [];
        if (is_string($middlewares)) {
            $middlewares = [$middlewares];
        }

        return $middlewares;
    }

    private function getControllerData(Route $route): array
    {
        $controllerAction = $route->getAction()['uses'];

        try {
            return explode('@', $controllerAction);
        } catch (Throwable) {
            return [null, null];
        }
    }

    private function extractPlaceholders($url): array
    {
        // Use regular expression to match content inside curly braces
        preg_match_all('/\{([^\/]+?)\}/', $url, $matches);

        // Return the matches found
        return $matches[1];

        return array_map(function ($element) {
            return Str::snake($element);
        }, $matches[1]);
    }

    private function isVendorClass(mixed $controller): bool
    {
        $class = new ReflectionClass($controller);

        return str_contains($class->getFileName(), 'vendor/');
    }

    private function getRuleAttribute(int|string $ruleName, array $attributes): ?ReflectionAttribute
    {
        /** @var ReflectionAttribute $data */
        foreach ($attributes as $data) {
            if ($data->getName() !== Parameter::class) {
                continue;
            }

            $name = $data->getArguments()['name'] ?? null;

            if ($name && $name === $ruleName) {
                return $data;
            }
        }

        return null;
    }

    private function getSimplifiedRoute(string $uri): string
    {
        return preg_replace('/\{[^}]+\}/', '*', $uri);
    }

    private function matchUri(string $uri, string $pattern): bool
    {
        $uri = $this->getSimplifiedRoute($uri);
        // Check if the pattern is negated
        $isNegated = false;
        if (substr($pattern, 0, 1) === '!') {
            $isNegated = true;
            $pattern = substr($pattern, 1); // Remove the negation symbol '!'
        }

        // Convert pattern's asterisks (*) into a regex equivalent that matches any string or path segment.
        $regex = str_replace(
            '*', // Replace '*' in the pattern
            '.*', // '*' becomes '.*' (matches anything, including slashes)
            $pattern
        );

        // Escape slashes in the pattern for the regex
        $regex = str_replace('/', '\/', $regex);

        // Add start and end anchors for the regex pattern to match the whole string
        $regex = '/^'.$regex.'$/';

        // Test if the pattern matches the URI
        $matches = preg_match($regex, $uri) === 1;

        // Handle negated patterns
        if ($isNegated) {
            return ! $matches; // Return false if it matches a negated pattern
        }

        return $matches;
    }
}
