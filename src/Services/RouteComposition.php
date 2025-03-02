<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use JkBennemann\LaravelApiDocumentation\Attributes\PathParameter;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
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
        $routes = [];

        /** @var Route $route */
        foreach ($this->routes as $route) {
            $httpMethod = $route->methods()[0];
            $uri = $this->getSimplifiedRoute($route->uri());
            try {
                $controller = $route->getController();
            } catch (Throwable) {
                continue;
            }

            $action = $route->getActionMethod();

            if (! $controller || ! $action) {
                continue;
            }

            try {
                $actionMethod = new ReflectionMethod($controller, $action);
            } catch (\ReflectionException $e) {
                continue;
            }
            $middlewares = $route->middleware();
            $isVendorClass = $this->isVendorClass(get_class($controller));
            $tags = $this->processTags($controller, $action);
            $description = $this->processDescription($controller, $action);

            $routes[] = [
                'method' => $httpMethod,
                'uri' => $uri,
                'summary' => $this->processSummary($controller, $action),
                'description' => $description,
                'middlewares' => $middlewares,
                'is_vendor' => $isVendorClass,
                'parameters' => $this->processRequestParameters($controller, $action),
                'request_parameters' => $this->processPathParameters($uri, $controller, $action),
                'tags' => array_filter($tags),
                'documentation' => null,
                'responses' => $this->processReturnType($actionMethod),
                'action' => [
                    'controller' => get_class($controller),
                    'method' => $action,
                ],
            ];
        }

        return $routes;
    }

    private function extractPathPlaceholders(string $uri): array
    {
        $parameters = [];
        preg_match_all('/{([^}]+)}/', $uri, $matches);

        foreach ($matches[1] as $name) {
            $parameters[$name] = [
                'name' => $name,
                'description' => '',
                'type' => 'string',
                'format' => null,
                'required' => true,
                'deprecated' => false,
                'parameters' => [],
            ];
        }

        return $parameters;
    }

    private function processRequestParameters($controller, string $action): array
    {
        $parameters = [];
        try {
            $method = new ReflectionMethod($controller, $action);

            // First try to get parameters from FormRequest
            foreach ($method->getParameters() as $parameter) {
                $parameterType = $parameter->getType();
                if (! $parameterType) {
                    continue;
                }

                $typeName = $parameterType->getName();
                if (is_a($typeName, FormRequest::class, true)) {
                    $requestClass = new $typeName;
                    $rules = $requestClass->rules();

                    // Get Parameter attributes from the class
                    $requestReflection = new ReflectionClass($typeName);
                    $rulesMethod = $requestReflection->getMethod('rules');
                    $parameterAttributes = $rulesMethod->getAttributes('JkBennemann\\LaravelApiDocumentation\\Attributes\\Parameter');

                    $attributeParams = [];
                    foreach ($parameterAttributes as $attribute) {
                        $args = $attribute->getArguments();
                        $name = $args['name'];
                        $attributeParams[$name] = [
                            'description' => $args['description'] ?? null,
                            'format' => $args['format'] ?? null,
                            'required' => $args['required'] ?? false,
                        ];
                    }

                    // Group rules by base parameter
                    $groupedRules = [];
                    foreach ($rules as $name => $rule) {
                        $parts = explode('.', $name);
                        $base = $parts[0];

                        if (! isset($groupedRules[$base])) {
                            $groupedRules[$base] = [];
                        }

                        if (count($parts) > 1) {
                            $subKey = implode('.', array_slice($parts, 1));
                            $groupedRules[$base][$subKey] = $rule;
                        } else {
                            $groupedRules[$base]['_rule'] = $rule;
                        }
                    }

                    // Process each base parameter
                    foreach ($groupedRules as $base => $baseRules) {
                        $baseRule = $baseRules['_rule'] ?? '';
                        $baseRuleArray = is_string($baseRule) ? explode('|', $baseRule) : $baseRule;

                        $parameters[$base] = [
                            'name' => $base,
                            'description' => $attributeParams[$base]['description'] ?? null,
                            'type' => $this->determineParameterType($baseRuleArray),
                            'format' => $this->determineParameterFormat($baseRuleArray),
                            'required' => in_array('required', $baseRuleArray),
                            'deprecated' => false,
                            'parameters' => [],
                        ];

                        // Process nested parameters
                        unset($baseRules['_rule']);
                        foreach ($baseRules as $subKey => $subRule) {
                            $subRuleArray = is_string($subRule) ? explode('|', $subRule) : $subRule;
                            $subParts = explode('.', $subKey);
                            $subName = end($subParts);

                            $parameters[$base]['parameters'][$subName] = [
                                'name' => $subName,
                                'description' => $attributeParams[$subKey]['description'] ?? null,
                                'type' => $this->determineParameterType($subRuleArray),
                                'format' => $this->determineParameterFormat($subRuleArray),
                                'required' => in_array('required', $subRuleArray),
                                'deprecated' => false,
                            ];
                        }
                    }

                    return $parameters;
                }
            }

            // If no FormRequest found, try to get parameters from method body
            $methodBody = file_get_contents($method->getFileName());
            $startLine = $method->getStartLine() - 1;
            $endLine = $method->getEndLine() - $startLine;
            $methodCode = implode('', array_slice(file($method->getFileName()), $startLine, $endLine));

            // Extract validation rules from $request->validate() calls
            if (preg_match('/\$request->validate\(\s*\[(.*?)\]\s*\)/s', $methodCode, $matches)) {
                $rulesString = $matches[1];
                // Parse the validation rules
                preg_match_all("/['\"]([\w_-]+)['\"](?:\s*=>\s*)['\"](.*?)['\"]/", $rulesString, $ruleMatches);

                for ($i = 0; $i < count($ruleMatches[1]); $i++) {
                    $name = $ruleMatches[1][$i];
                    $rules = explode('|', $ruleMatches[2][$i]);

                    $parameters[$name] = [
                        'name' => $name,
                        'description' => null,
                        'type' => $this->determineParameterType($rules),
                        'format' => $this->determineParameterFormat($rules),
                        'required' => in_array('required', $rules),
                        'deprecated' => false,
                    ];
                }
            }
        } catch (Throwable $e) {
            // Log error but continue processing
        }

        return $parameters;
    }

    private function determineParameterType(array $rules): string
    {
        $typeMap = [
            'numeric' => 'number',
            'integer' => 'integer',
            'boolean' => 'boolean',
            'array' => 'array',
            'file' => 'string',
            'string' => 'string',
            'email' => 'string',
        ];

        foreach ($rules as $rule) {
            if (isset($typeMap[$rule])) {
                return $typeMap[$rule];
            }
        }

        return 'string';
    }

    private function determineParameterFormat(array $rules): ?string
    {
        if (! $rules) {
            return null;
        }

        $formatMap = [
            'email' => 'email',
            'url' => 'url',
            'date' => 'date',
            'date_format' => 'date-time',
            'uuid' => 'uuid',
            'ip' => 'ipv4',
            'ipv4' => 'ipv4',
            'ipv6' => 'ipv6',
        ];

        foreach ($rules as $rule) {
            if (isset($formatMap[$rule])) {
                return $formatMap[$rule];
            }
        }

        return null;
    }

    private function getControllerData(Route $route): array
    {
        $action = $route->getAction();

        if (! isset($action['controller'])) {
            return [null, null];
        }

        $parts = explode('@', $action['controller']);
        if (count($parts) !== 2) {
            return [null, null];
        }

        return $parts;
    }

    private function shouldBeSkipped(string $uri, string $httpMethod, bool $isVendorClass): bool
    {
        if (! $this->includeVendorRoutes && $isVendorClass) {
            return true;
        }

        if (in_array($httpMethod, $this->excludedMethods, true)) {
            return true;
        }

        foreach ($this->excludedRoutes as $excludedRoute) {
            if (str_is($excludedRoute, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function getMiddlewares(Route $route): array
    {
        return array_map(function ($middleware) {
            if (is_string($middleware)) {
                return $middleware;
            }
            if (is_array($middleware) && isset($middleware[0])) {
                return $middleware[0];
            }

            return '';
        }, $route->gatherMiddleware());
    }

    private function isVendorClass(string $class): bool
    {
        return str_contains($class, 'vendor');
    }

    private function processParameters(Route $route): array
    {
        $parameters = [];
        $parameterNames = $route->parameterNames();

        foreach ($parameterNames as $name) {
            $parameters[$name] = [
                'name' => $name,
                'description' => '',
                'type' => 'string',
                'format' => null,
                'required' => true,
                'deprecated' => false,
                'parameters' => [],
            ];
        }

        return $parameters;
    }

    private function processReturnType(ReflectionMethod $method): array
    {
        $responses = [];
        $returnType = $method->getReturnType();
        $statusCode = 200;
        $description = '';
        $headers = [];
        $resource = null;

        // Check for DataResponse attribute
        $dataResponseAttr = $method->getAttributes(DataResponse::class)[0] ?? null;
        if ($dataResponseAttr) {
            $args = $dataResponseAttr->getArguments();
            $statusCode = $args['status'] ?? 200;
            $description = $args['description'] ?? '';
            $headers = $args['headers'] ?? [];
            $resource = $args['resource'] ?? null;
        }

        if ($returnType === null) {
            $responses[$statusCode] = [
                'description' => $description,
                'resource' => $resource,
                'headers' => $headers,
                'type' => 'object',
                'content_type' => 'application/json',
            ];
        } else {
            $typeName = $returnType->getName();

            if (is_a($typeName, Collection::class, true)) {
                $responses[$statusCode] = [
                    'description' => $description,
                    'resource' => $resource ?? $typeName,
                    'headers' => $headers,
                    'type' => 'array',
                    'content_type' => 'application/json',
                    'items' => [
                        'type' => 'object',
                    ],
                ];
            } elseif (is_a($typeName, ResourceCollection::class, true) || is_a($typeName, AnonymousResourceCollection::class, true)) {
                $responses[$statusCode] = [
                    'description' => $description,
                    'resource' => $resource ?? $typeName,
                    'headers' => $headers,
                    'type' => 'object',
                    'content_type' => 'application/json',
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                            ],
                        ],
                        'meta' => ['type' => 'object'],
                        'links' => ['type' => 'object'],
                    ],
                ];
            } elseif (is_a($typeName, JsonResource::class, true)) {
                $responses[$statusCode] = [
                    'description' => $description,
                    'resource' => $resource ?? $typeName,
                    'headers' => $headers,
                    'type' => 'object',
                    'content_type' => 'application/json',
                ];
            } elseif (is_a($typeName, JsonResponse::class, true)) {
                $responses[$statusCode] = [
                    'description' => $description,
                    'resource' => $resource ?? $typeName,
                    'headers' => $headers,
                    'type' => 'object',
                    'content_type' => 'application/json',
                ];
            } else {
                $responses[$statusCode] = [
                    'description' => $description,
                    'resource' => $resource ?? $typeName,
                    'headers' => $headers,
                    'type' => 'object',
                    'content_type' => 'application/json',
                ];
            }
        }

        // Add validation error response if method has validation
        if ($this->hasValidation($method)) {
            $responses[422] = [
                'description' => 'Validation error response',
                'resource' => null,
                'headers' => [],
                'type' => 'object',
                'content_type' => 'application/json',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => ['type' => 'object'],
                ],
            ];
        }

        return $responses;
    }

    private function hasValidation(ReflectionMethod $method): bool
    {
        try {
            $methodBody = file_get_contents($method->getFileName());
            $startLine = $method->getStartLine() - 1;
            $endLine = $method->getEndLine() - $startLine;
            $methodCode = implode('', array_slice(file($method->getFileName()), $startLine, $endLine));

            return str_contains($methodCode, '$request->validate') ||
                   str_contains($methodCode, 'ValidationException');
        } catch (Throwable) {
            return false;
        }
    }

    private function getSimplifiedRoute(string $uri): string
    {
        return $uri;
    }

    private function getRuleAttribute(string $ruleName, array $attributes): ?ReflectionAttribute
    {
        /** @var ReflectionAttribute $data */
        foreach ($attributes as $data) {
            if (str_ends_with($data->getName(), $ruleName)) {
                return $data;
            }
        }

        return null;
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

    private function processValidationRules(ReflectionMethod $method): array
    {
        $parameters = [];

        // Get validation rules from method parameters
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getType() && ! $parameter->getType()->isBuiltin()) {
                $typeName = $parameter->getType()->getName();
                if (is_a($typeName, Request::class, true)) {
                    try {
                        // Check for validate method call in the method body
                        $methodBody = file_get_contents($method->getFileName());
                        $startLine = $method->getStartLine() - 1;
                        $endLine = $method->getEndLine() - $startLine;
                        $methodCode = implode('', array_slice(file($method->getFileName()), $startLine, $endLine));

                        if (preg_match('/\$request->validate\(\s*\[(.*?)\]\s*\)/s', $methodCode, $matches)) {
                            $rulesString = $matches[1];
                            // Parse the validation rules
                            preg_match_all('/\'(.*?)\'\s*=>\s*\'(.*?)\'/', $rulesString, $ruleMatches);

                            for ($i = 0; $i < count($ruleMatches[1]); $i++) {
                                $field = $ruleMatches[1][$i];
                                $rules = explode('|', $ruleMatches[2][$i]);

                                $parameter = [
                                    'name' => $field,
                                    'description' => '',
                                    'type' => 'string',
                                    'format' => null,
                                    'required' => in_array('required', $rules),
                                    'deprecated' => false,
                                ];

                                // Process rules
                                foreach ($rules as $rule) {
                                    if ($rule === 'email') {
                                        $parameter['format'] = 'email';
                                    } elseif (in_array($rule, ['numeric', 'integer'])) {
                                        $parameter['type'] = 'integer';
                                    }
                                }

                                $parameters[$field] = $parameter;
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('Error processing validation rules: '.$e->getMessage());
                    }
                }
            }
        }

        return $parameters;
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            'int' => 'integer',
            default => $type,
        };
    }

    private function processPathParameters(string $uri, $controller, string $action): array
    {
        $parameters = [];
        preg_match_all('/{([^}]+)}/', $uri, $matches);
        $method = new ReflectionMethod($controller, $action);
        $pathParamAttrs = $method->getAttributes(PathParameter::class);

        // First, collect all PathParameter attributes
        $pathParams = [];
        foreach ($pathParamAttrs as $attr) {
            $args = $attr->getArguments();
            if (isset($args['name'])) {
                $pathParams[] = $args;
            }
        }

        // Then process each URI parameter
        foreach ($matches[1] as $index => $name) {
            $cleanName = trim($name, '?');
            $isOptional = str_contains($name, '?');

            // Find matching PathParameter attribute
            $pathParam = $pathParams[$index] ?? null;

            if ($pathParam) {
                $type = $this->normalizeType($pathParam['type'] ?? 'string');
                $format = $pathParam['format'] ?? null;
                $example = $pathParam['example'] ?? null;

                $parameters[$pathParam['name']] = [
                    'description' => $pathParam['description'] ?? '',
                    'required' => $isOptional ? false : ($pathParam['required'] ?? true),
                    'type' => $type,
                    'format' => $format,
                    'example' => $example ? [
                        'type' => $type,
                        'format' => $format,
                        'value' => $example,
                    ] : null,
                ];
            } else {
                // Default values if no attribute found
                $parameters[$cleanName] = [
                    'description' => '',
                    'required' => ! $isOptional,
                    'type' => 'string',
                    'format' => null,
                    'example' => null,
                ];
            }
        }

        return $parameters;
    }

    private function processTags($controller, string $action): array
    {
        $tags = [];
        try {
            $method = new ReflectionMethod($controller, $action);
            $attributes = $method->getAttributes();

            foreach ($attributes as $attribute) {
                if ($attribute->getName() === Tag::class) {
                    $args = $attribute->getArguments();
                    if (empty($args)) {
                        continue;
                    }

                    $tagValue = $args[0];
                    if (is_string($tagValue)) {
                        $tags = array_merge($tags, explode(',', $tagValue));
                    } elseif (is_array($tagValue)) {
                        $tags = array_merge($tags, $tagValue);
                    }
                }
            }
        } catch (Throwable) {
            // Ignore reflection errors
        }

        return array_map('trim', $tags);
    }

    private function processSummary($controller, string $action): ?string
    {
        try {
            $method = new ReflectionMethod($controller, $action);
            $attributes = $method->getAttributes();

            foreach ($attributes as $attribute) {
                if ($attribute->getName() === Summary::class) {
                    $args = $attribute->getArguments();

                    return $args[0] ?? null;
                }
            }
        } catch (Throwable) {
            // Ignore reflection errors
        }

        return null;
    }

    private function processDescription($controller, string $action): ?string
    {
        try {
            $method = new ReflectionMethod($controller, $action);
            $attributes = $method->getAttributes();

            foreach ($attributes as $attribute) {
                if ($attribute->getName() === Description::class) {
                    $args = $attribute->getArguments();

                    return $args[0] ?? null;
                }
            }
        } catch (Throwable) {
            // Ignore reflection errors
        }

        return null;
    }
}
