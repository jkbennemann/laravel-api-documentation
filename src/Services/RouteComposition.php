<?php

declare(strict_types=1);

namespace Bennemann\LaravelApiDocumentation\Services;

use Bennemann\LaravelApiDocumentation\Attributes\AdditionalDocumentation;
use Bennemann\LaravelApiDocumentation\Attributes\DataResponse;
use Bennemann\LaravelApiDocumentation\Attributes\Description;
use Bennemann\LaravelApiDocumentation\Attributes\Parameter;
use Bennemann\LaravelApiDocumentation\Attributes\Summary;
use Bennemann\LaravelApiDocumentation\Attributes\Tag;
use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
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

            if (null === $controller || null === $action) {
                continue;
            }

            $isVendorClass = $this->isVendorClass($controller);
            $urlParams = $this->extractPlaceholders($uri);
            $description = null;
            $summary = null;
            $tags = null;
            $url = null;
            $additionalDescription = null;
            $validationRules = [];
            $responses = [];


            if (!$this->includeVendorRoutes && $isVendorClass) {
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
            }

            foreach ($actionMethod->getParameters() as $parameter) {
                $parameterType = $parameter->getType();
                if ($parameterType === null) {
                    continue;
                }
                if (!$parameterType->isBuiltin()) {
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

                            foreach ($availableRules as $ruleName => $rules) {
                                $ruleAttribute = $this->getRuleAttribute($ruleName, $attributes);

                                if (null === $ruleAttribute) {
                                    //take data from rule array
                                    $isRequired = false;
                                    $type = ''; //default to string
                                    $format = null;
                                    foreach ($rules as $r) {
                                        if (is_scalar($r) && stripos($r, 'required') !== false) {
                                            $isRequired = true;
                                        }

                                        if ($type === 'string' || (is_scalar($r) && $this->isStringParameter($r))) {
                                            $type = 'string';
                                        }

                                        if ((is_scalar($r) && $this->isBooleanParameter($r))) {
                                            $type = 'string';
                                            $format = 'boolean';
                                        }
                                    }

                                    if (empty($type)) {
                                        $type = null;
                                    }

                                    $validationRules[$ruleName] = [
                                        'name' => $ruleName,
                                        'is_required' => $isRequired,
                                        'type' => $type,
                                        'format' => $format,
                                        'description' => '',
                                        'is_deprecated' => false,
                                    ];

                                    continue;
                                }

                                $validationRules[$ruleName] = [
                                    'name' => $ruleAttribute->getName(),
                                    'is_required' => $ruleAttribute->getArguments()['required'] ?? false,
                                    'type' => $ruleAttribute->getArguments()['type'] ?? 'string',
                                    'format' => $ruleAttribute->getArguments()['format'] ?? null,
                                    'description' => $ruleAttribute->getArguments()['description'] ?? '',
                                    'is_deprecated' => $ruleAttribute->getArguments()['deprecated'] ?? false,
                                ];
                            }
                        }
                    }
                }
            }

            $parameterKeys = array_diff(array_keys($validationRules), array_map(function ($element) {
                return Str::snake($element);
            }, $urlParams));


            //compose parameter keys into associative array
//            $output = [];
//
//            foreach ($parameterKeys as $item) {
//                $parts = explode('.', $item);
//
//                // If there's only one part, it's a standalone key-value
//                if (count($parts) === 1) {
//                    $output[$item] = $item;
//                } else {
//                    // Extract the last part (actual value) and the rest of the parts as keys
//                    $value = array_pop($parts);
//                    $this->insertIntoArray($output, $parts, $value);
//                }
//            }
//
            $parameters = [];
            foreach ($validationRules as $key => $values) {
//                $finding = $this->getNestedValue($output, $key);
                if (in_array(Str::snake($key), $parameterKeys)) {
                    $parameters[$key] = $values;
                }
            }


            $all[] = [
                'method' => $httpMethod,
                'uri' => $uri,
                'summary' => $summary,
                'description' => $description,
                'middlewares' => join(',', $middleswares),
                'is_vendor' => $isVendorClass,
                'request_parameters' => join(',', $urlParams), //TODO: make possible to use PathParameter attribute
                'parameters' => $parameters,
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

    private function insertIntoArray(&$array, $keys, $value) {
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            } elseif (!is_array($current[$key])) {
                // If it's not already an array, make it an array
                $current[$key] = [$current[$key]];
            }
            $current = &$current[$key];
        }

        // Ensure that $current is an array before checking or adding the value
        if (!is_array($current)) {
            $current = [$current];
        }

        // Append the value to the array at the final position if it doesn't already exist
        if (!in_array($value, $current)) {
            $current[] = $value;
        }
    }

    private function getNestedValue($array, $path) {
        $keys = explode('.', $path);  // Split the path by the dot
        $current = $array;

        foreach ($keys as $key) {
            if (isset($current[$key])) {
                $current = $current[$key];  // Navigate deeper into the array
            } else {
                return null;  // Key doesn't exist
            }
        }

        return $current;  // Return the final value
    }

    private function getMiddlewares(Route $route): array
    {
        $middleswares = $route->getAction('middleware') ?? [];
        if (is_string($middleswares)) {
            $middleswares = [$middleswares];
        }

        return $middleswares;
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

    private function isStringParameter(mixed $r): bool
    {
        if (!is_string($r)) {
            return false;
        }

        foreach (['date', 'date_equals', 'date_format', 'string', 'between', 'password', 'email', 'phone', 'uuid', 'regex', 'in'] as $item) {
            if (stripos($r, $item) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isBooleanParameter(mixed $r): bool
    {
        if (!is_string($r)) {
            return false;
        }

        foreach (['boolean', 'confirmed', 'accepted'] as $item) {
            if (stripos($r, $item) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getSimplifiedRoute(string $uri): string
    {
        return preg_replace('/\{[^}]+\}/', '*', $uri);
    }

    private function matchUri(string $uri, string $pattern): bool {
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
        $regex = '/^' . $regex . '$/';

        // Test if the pattern matches the URI
        $matches = preg_match($regex, $uri) === 1;

        // Handle negated patterns
        if ($isNegated) {
            return !$matches; // Return false if it matches a negated pattern
        }

        return $matches;
    }
}
