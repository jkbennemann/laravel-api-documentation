<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Exception;
use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use JkBennemann\LaravelApiDocumentation\Services\Traits\HandlesSmartFeatures;
use openapiphp\openapi\spec\Components;
use openapiphp\openapi\spec\Header;
use openapiphp\openapi\spec\Info;
use openapiphp\openapi\spec\MediaType;
use openapiphp\openapi\spec\Operation;
use openapiphp\openapi\spec\Parameter;
use openapiphp\openapi\spec\PathItem;
use openapiphp\openapi\spec\RequestBody;
use openapiphp\openapi\spec\Response;
use openapiphp\openapi\spec\Schema;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Throwable;
use JkBennemann\LaravelApiDocumentation\Services\AstAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\AttributeAnalyzer;

class OpenApi
{
    use HandlesSmartFeatures;

    private \openapiphp\openapi\spec\OpenApi $openApi;

    private Repository $repository;

    private ?AttributeAnalyzer $attributeAnalyzer;
    
    private ?AstAnalyzer $astAnalyzer;

    private array $excludedRoutes;

    private array $excludedMethods;

    private array $includedSecuritySchemes = [];

    public function __construct(
        Repository $repository,
        ?AttributeAnalyzer $attributeAnalyzer = null,
        ?AstAnalyzer $astAnalyzer = null
    ) {
        $this->repository = $repository;
        $this->attributeAnalyzer = $attributeAnalyzer ?? app(AttributeAnalyzer::class);
        $this->astAnalyzer = $astAnalyzer ?? app(AstAnalyzer::class);
        $this->excludedRoutes = $repository->get('api-documentation.excluded_routes', []);
        $this->excludedMethods = $repository->get('api-documentation.excluded_methods', []);
        $this->includedSecuritySchemes = [];

        $this->openApi = new \openapiphp\openapi\spec\OpenApi([
            'openapi' => '3.0.2',
            'info' => new Info([
                'title' => $repository->get('api-documentation.title', 'API Documentation'),
                'version' => $repository->get('api-documentation.version', '1.0.0'),
            ]),
            'servers' => $repository->get('api-documentation.servers', [
                [
                    'url' => 'http://localhost',
                    'description' => 'Local server',
                ],
            ]),
            'paths' => new \openapiphp\openapi\spec\Paths([]),
            'components' => new Components([]),
        ]);

        $this->initializeSmartFeatures();
    }

    public function get(): \openapiphp\openapi\spec\OpenApi
    {
        // Ensure we have the latest config values before returning
        $this->updateInfoFromConfig();
        return $this->openApi;
    }

    private function updateInfoFromConfig(): void
    {
        $this->openApi->info->title = $this->repository->get('api-documentation.title', 'API Documentation');
        $this->openApi->info->version = $this->repository->get('api-documentation.version', '1.0.0');
    }

    private function setSecuritySchemes(): void
    {
        if (! empty($this->includedSecuritySchemes)) {
            $schemas = [];
            foreach ($this->includedSecuritySchemes as $scheme) {
                if ($scheme === 'jwt') {
                    $schemas[$scheme] = [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ];
                } else {
                    $schemas[$scheme] = [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ];
                }
            }

            if (! empty($schemas)) {
                $this->openApi->components = new Components([
                    'securitySchemes' => $schemas,
                ]);
            }
        }

    }

    public function processRoutes(array $routes): self
    {
        $paths = new \openapiphp\openapi\spec\Paths([]);

        foreach ($routes as $route) {
            if ($this->shouldSkipRoute($route)) {
                continue;
            }

            $uri = $route['uri'];
            if (! str_starts_with($uri, '/')) {
                $uri = '/'.$uri;
            }

            // Get or create path item
            $pathItem = $paths[$uri] ?? new PathItem([]);

            // Create operation
            $operation = $this->createOperation($route);

            // Add security if middleware contains auth
            if (!empty($route['middlewares'])) {
                $this->processSecuritySchemes($route['middlewares'], $operation, $this->includedSecuritySchemes);
            }

            // Set operation based on HTTP method
            $method = strtolower($route['method']);
            switch ($method) {
                case 'get':
                    $pathItem->get = $operation;
                    break;
                case 'post':
                    $pathItem->post = $operation;
                    break;
                case 'put':
                    $pathItem->put = $operation;
                    break;
                case 'patch':
                    $pathItem->patch = $operation;
                    break;
                case 'delete':
                    $pathItem->delete = $operation;
                    break;
                case 'options':
                    $pathItem->options = $operation;
                    break;
                case 'head':
                    $pathItem->head = $operation;
                    break;
                case 'trace':
                    $pathItem->trace = $operation;
                    break;
            }

            $paths[$uri] = $pathItem;
        }

        $this->openApi->paths = $paths;
        $this->setSecuritySchemes();

        return $this;
    }

    private function createOperation(array $route): Operation
    {
        // Initialize with default response
        $defaultResponse = new Response([
            'description' => '',
            'content' => [
                'application/json' => new MediaType([
                    'schema' => new Schema(['type' => 'object']),
                ]),
            ],
        ]);

        $operation = new Operation([
            'summary' => $route['summary'] ?? '',
            'description' => $route['description'] ?? '',
            'tags' => array_values(array_filter($route['tags'] ?? [])),
            'parameters' => [],
            'responses' => [
                '200' => $defaultResponse,
            ],
        ]);

        // Get method reflection if available
        $methodReflection = null;
        if (isset($route['controller']) && isset($route['action'])) {
            try {
                $controllerReflection = new ReflectionClass($route['controller']);
                $methodReflection = $controllerReflection->getMethod($route['action']);
            } catch (Throwable $e) {
                // Continue without reflection
            }
        }

        // 1. Handle Parameters (Path, Query)
        $this->processOperationParameters($operation, $route, $methodReflection);

        // 2. Handle Request Body
        $this->processOperationRequestBody($operation, $route, $methodReflection);

        // 3. Handle Responses
        $this->processOperationResponses($operation, $route, $methodReflection);

        return $operation;
    }

    /**
     * Process operation parameters (path and query parameters)
     */
    private function processOperationParameters(Operation $operation, array $route, ?ReflectionMethod $methodReflection): void
    {
        $parameters = [];

        // Add path parameters from route data
        if (!empty($route['request_parameters'])) {
            foreach ($route['request_parameters'] as $name => $param) {
                $parameters[] = new Parameter([
                    'name' => $name,
                    'in' => 'path',
                    'description' => $param['description'] ?? '',
                    'required' => $param['required'] ?? true,
                    'schema' => new Schema([
                        'type' => $param['type'] ?? 'string',
                        'format' => $param['format'] ?? null,
                    ]),
                ]);
            }
        }

        // Add query parameters from pre-processed route data (includes both attribute and smart detection)
        if (!empty($route['query_parameters'])) {
            foreach ($route['query_parameters'] as $name => $param) {
                $schema = new Schema([
                    'type' => $param['type'] ?? 'string',
                    'format' => $param['format'] ?? null,
                ]);
                
                // Add enum values if present
                if (!empty($param['enum'])) {
                    $schema->enum = $param['enum'];
                }
                
                $parameters[] = new Parameter([
                    'name' => $name,
                    'in' => 'query',
                    'description' => $param['description'] ?? '',
                    'required' => $param['required'] ?? false,
                    'schema' => $schema,
                    'example' => $param['example'] ?? null,
                ]);
            }
        }

        if (!empty($parameters)) {
            $operation->parameters = $parameters;
        }
    }

    /**
     * Process operation request body
     */
    private function processOperationRequestBody(Operation $operation, array $route, ?ReflectionMethod $methodReflection): void
    {
        $requestBody = null;

        // Check for explicit request body from pre-processed route data (priority)
        if (!empty($route['request_body'])) {
            $explicitRequestBody = $route['request_body'];
            $schema = null;
            
            if ($explicitRequestBody['data_class']) {
                // Use Spatie Data class for schema
                $schemaData = $this->responseAnalyzer->analyzeSpatieDataObject($explicitRequestBody['data_class']);
                $schema = new Schema($this->convertSpatieDataSchemaToOpenApi($schemaData));
            } else {
                $schema = new Schema(['type' => 'object']);
            }

            $requestBody = new RequestBody([
                'description' => $explicitRequestBody['description'],
                'required' => $explicitRequestBody['required'],
                'content' => [
                    $explicitRequestBody['content_type'] => new MediaType([
                        'schema' => $schema,
                        'example' => $explicitRequestBody['example'],
                    ]),
                ],
            ]);
        }

        // Fall back to smart detection if no explicit definition
        if (!$requestBody && !empty($route['parameters']) && $this->repository->get('api-documentation.smart_features', true)) {
            // Enhanced schema building with AST analysis if available
            $schema = $this->buildRequestBodySchema($route['parameters']);
            
            // Add additional schema enhancements from AST analysis if method reflection is available
            if ($methodReflection && $this->astAnalyzer) {
                $this->enhanceSchemaWithAstAnalysis($schema, $methodReflection);
            }
            
            $requestBody = new RequestBody([
                'required' => true,
                'content' => [
                    'application/json' => new MediaType([
                        'schema' => $schema,
                    ]),
                ],
            ]);
        }

        if ($requestBody) {
            $operation->requestBody = $requestBody;
        }
    }

    /**
     * Process operation responses
     */
    private function processOperationResponses(Operation $operation, array $route, ?ReflectionMethod $methodReflection): void
    {
        $responses = [];

        // Check for explicit response body from pre-processed route data (priority)
        if (!empty($route['response_bodies'])) {
            foreach ($route['response_bodies'] as $statusCode => $responseData) {
                $schema = null;
                
                if ($responseData['data_class']) {
                    $schemaData = $this->responseAnalyzer->analyzeSpatieDataObject($responseData['data_class']);
                    
                    if ($responseData['is_collection']) {
                        $schema = new Schema([
                            'type' => 'array',
                            'items' => new Schema($this->convertSpatieDataSchemaToOpenApi($schemaData)),
                        ]);
                    } else {
                        $schema = new Schema($this->convertSpatieDataSchemaToOpenApi($schemaData));
                    }
                } else {
                    $schema = new Schema(['type' => 'object']);
                }

                // Process response headers from pre-processed route data
                $responseHeaders = [];
                if (!empty($route['response_headers'])) {
                    foreach ($route['response_headers'] as $headerName => $headerData) {
                        if (is_string($headerData)) {
                            $responseHeaders[$headerName] = new Header([
                                'description' => $headerData,
                                'schema' => [
                                    'type' => 'string',
                                ],
                            ]);
                        } else {
                            $responseHeaders[$headerName] = new Header([
                                'description' => $headerData['description'],
                                'required' => $headerData['required'],
                                'schema' => new Schema([
                                    'type' => $headerData['type'],
                                    'format' => $headerData['format'],
                                ]),
                                'example' => $headerData['example'],
                            ]);
                        }
                    }
                }

                $responses[$statusCode] = new Response([
                    'description' => $responseData['description'],
                    'headers' => $responseHeaders,
                    'content' => [
                        $responseData['content_type'] => new MediaType([
                            'schema' => $schema,
                            'example' => $this->filterSpatieInternalFields($responseData['example']),
                        ]),
                    ],
                ]);
            }
        }

        // Process responses from RouteComposition (includes validation errors, etc.)
        if (!empty($route['responses'])) {
            foreach ($route['responses'] as $statusCode => $responseData) {
                // Skip if already processed from response_bodies (higher priority)
                if (isset($responses[$statusCode])) {
                    continue;
                }

                $schema = null;
                
                // Handle responses with properties (like validation errors)
                if (!empty($responseData['properties'])) {
                    $properties = [];
                    foreach ($responseData['properties'] as $propName => $propData) {
                        // Rule: Keep the code clean and readable - Filter out Spatie internal fields
                        if ($propName === '_additional' || $propName === '_data_context') {
                            continue;
                        }
                        
                        $properties[$propName] = new Schema([
                            'type' => $propData['type'] ?? 'string',
                            'description' => $propData['description'] ?? '',
                        ]);
                    }
                    $schema = new Schema([
                        'type' => $responseData['type'] ?? 'object',
                        'properties' => $properties,
                    ]);
                } else {
                    $schema = new Schema([
                        'type' => $responseData['type'] ?? 'object',
                    ]);
                }

                $responseHeaders = [];
                if (!empty($responseData['headers'])) {
                    foreach ($responseData['headers'] as $headerName => $headerData) {
                        if (is_string($headerData)) {
                            $responseHeaders[$headerName] = new Header([
                                'description' => $headerData,
                                'schema' => [
                                    'type' => 'string',
                                ],
                            ]);
                        } else {
                            $responseHeaders[$headerName] = new Header([
                                'description' => $headerData['description'],
                                'required' => $headerData['required'],
                                'schema' => new Schema([
                                    'type' => $headerData['type'],
                                    'format' => $headerData['format'],
                                ]),
                                'example' => $headerData['example'],
                            ]);
                        }
                    }
                }

                // Prepare MediaType configuration with schema
                $mediaTypeConfig = [
                    'schema' => $schema,
                ];
                
                // Add example if available
                if (isset($responseData['example'])) {
                    $mediaTypeConfig['example'] = $this->filterSpatieInternalFields($responseData['example']);
                }
                
                $responses[$statusCode] = new Response([
                    'description' => $responseData['description'] ?? '',
                    'headers' => $responseHeaders,
                    'content' => [
                        $responseData['content_type'] ?? 'application/json' => new MediaType($mediaTypeConfig),
                    ],
                ]);
            }
        }

        // Ensure at least a 200 response exists
        if (empty($responses)) {
            $responses['200'] = new Response([
                'description' => '',
                'content' => [
                    'application/json' => new MediaType([
                        'schema' => new Schema(['type' => 'object']),
                    ]),
                ],
            ]);
        }

        $operation->responses = $responses;
    }

    private function buildRequestBodySchema(array $parameters): Schema
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $name => $param) {
            if (! empty($param['parameters'])) {
                // Handle nested parameters
                $nestedSchema = $this->buildRequestBodySchema($param['parameters']);
                $properties[$name] = new Schema([
                    'type' => 'object',
                    'properties' => $nestedSchema->properties,
                    'required' => $nestedSchema->required,
                ]);
            } else {
                $schemaProps = [
                    'type' => $param['type'] ?? 'string',
                    'format' => $param['format'] ?? null,
                    'description' => $param['description'] ?? '',
                ];
                
                // Add enum values if present
                if (!empty($param['enum'])) {
                    $schemaProps['enum'] = $param['enum'];
                }
                
                // Add example if present
                if (!empty($param['example'])) {
                    $schemaProps['example'] = $this->filterSpatieInternalFields($param['example']);
                }
                
                $properties[$name] = new Schema($schemaProps);
            }

            if ($param['required'] ?? false) {
                $required[] = $name;
            }
        }

        return new Schema([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ]);
    }
    
    /**
     * Check if route should be skipped
     */
    private function shouldSkipRoute(array $data): bool
    {
        foreach ($this->excludedRoutes as $excludedRoute) {
            if ($this->matchUri($data['uri'], $excludedRoute)) {
                return true;
            }
        }

        foreach ($this->excludedMethods as $excludedMethod) {
            if ($data['method'] === strtoupper($excludedMethod)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Enhance a schema with additional information from AST analysis
     */
    private function enhanceSchemaWithAstAnalysis(Schema $schema, ReflectionMethod $methodReflection): void
    {
        try {
            $filePath = $methodReflection->getFileName();
            $methodName = $methodReflection->getName();
            
            if (!$filePath || !file_exists($filePath)) {
                return;
            }
            
            // Analyze validation rules to enhance schema properties
            $rules = $this->astAnalyzer->extractValidationRules($filePath, $methodName);
            
            if (empty($rules) || empty($schema->properties)) {
                return;
            }
            
            // Enhance existing properties with additional validation information
            foreach ($rules as $name => $ruleData) {
                if (isset($schema->properties[$name])) {
                    // Add format if not already set
                    if (!$schema->properties[$name]->format && !empty($ruleData['format'])) {
                        $schema->properties[$name]->format = $ruleData['format'];
                    }
                    
                    // Add description if not already set
                    if (empty($schema->properties[$name]->description) && !empty($ruleData['description'])) {
                        $schema->properties[$name]->description = $ruleData['description'];
                    }
                }
            }
        } catch (Throwable $e) {
            // Silently fail - schema enhancement is optional
        }
    }

    /**
     * Process path parameters
     */
    private function processPathParameters($parameters): array
    {
        if (! is_array($parameters)) {
            $params = explode(',', $parameters);

            return array_map(fn ($param) => new Parameter([
                'name' => $param,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ]), $params);
        }

        return array_map(function ($key, $values) {
            $schema = ['type' => $values['type'] ?? 'string'];

            if (isset($values['format'])) {
                $schema['format'] = $values['format'];
            }

            $parameter = [
                'name' => $key,
                'in' => 'path',
                'required' => true,
                'description' => $values['description'] ?? '',
                'schema' => $schema,
            ];

            if (isset($values['example'])) {
                $example = is_array($values['example']) ? $values['example']['value'] : $values['example'];
                $parameter['example'] = $this->filterSpatieInternalFields($example);
            }

            return new Parameter($parameter);
        }, array_keys($parameters), $parameters);
    }

    /**
     * Process security schemes
     */
    private function processSecuritySchemes(array $middleware, Operation $operation, array &$includedSecuritySchemes): void
    {
        $security = [];
        foreach ($middleware as $m) {
            if (Str::contains($m, 'auth')) {
                if (Str::contains($m, 'jwt')) {
                    $security[] = ['jwt' => []];
                    $includedSecuritySchemes[] = 'jwt';
                } else {
                    $security[] = ['bearer' => []];
                    $includedSecuritySchemes[] = 'bearer';
                }
            }
        }

        if (! empty($security)) {
            $operation->security = $security;
        }
    }

    private function replacePlaceholdersForOpenApi(string $uri): string
    {
        // Ensure leading slash
        $path = '/'.ltrim($uri, '/');

        // Replace Laravel route parameters with OpenAPI parameters
        // e.g., {parameter} or {parameter?} becomes {parameter}
        return preg_replace('/\{([^}]+?)(\?)?\}/', '{$1}', $path);
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

    private function generateOpenAPIResponseSchema(array $response): Response
    {
        $resourceClass = $response['resource'];
        // Extract fields from the Laravel Resource class
        $fields = $this->extractFieldsFromToArray($resourceClass);

        // Detect if the response is wrapped
        $wrap = $this->detectWrapping($resourceClass);

        // Build the schema
        $schema = [
            'type' => 'object',
            'properties' => [],
        ];

        if ($wrap) {
            $schema['properties']['data'] = [
                'type' => 'object',
                'properties' => [],
            ];
        }

        foreach ($fields as $key => $value) {
            if ($wrap) {
                $schema['properties']['data']['properties'][$key] = $this->buildPropertySchema($value);
            } else {
                $schema['properties'][$key] = $this->buildPropertySchema($value);
            }
        }

        $responseData = [
            'description' => $response['description'] ?? '',
            'content' => [
                'application/json' => [
                    'schema' => new Schema($schema),
                ],
            ],
        ];

        // Add headers if present
        if (! empty($response['headers'])) {
            $headers = [];
            foreach ($response['headers'] as $key => $header) {
                if (is_string($header)) {
                    $headers[$key] = new Header([
                        'description' => $header,
                        'schema' => [
                            'type' => 'string',
                        ],
                    ]);
                } else {
                    $headers[$key] = new Header([
                        'description' => $header['description'],
                        'required' => $header['required'],
                        'schema' => new Schema([
                            'type' => $header['type'],
                            'format' => $header['format'],
                        ]),
                        'example' => $header['example'],
                    ]);
                }
            }
            $responseData['headers'] = $headers;
        }

        return new Response($responseData);
    }

    private function buildPropertySchema($value): array
    {
        if (is_array($value)) {
            if (isset($value['type']) && $value['type'] === 'array') {
                return [
                    'type' => 'array',
                    'items' => [], // Initialize to hold the inner fields
                ];
            }

            return $value;
        }

        [$type, $nullable] = explode('|', $value.'|false');
        $schema = ['type' => $this->mapPhpTypeToOpenApiType($type)];
        if ($nullable === 'true') {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    private function extractFieldsFromToArray(string $resourceClass): array
    {
        $fields = [];
        $reflection = new ReflectionClass($resourceClass);

        if ($reflection->isSubclassOf(Data::class)) {
            $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

            /** @var ReflectionAttribute $globalMapper */
            $globalMapper = $reflection->getAttributes(MapName::class);
            /** @var ReflectionAttribute $outputMapper */
            $outputMapper = $reflection->getAttributes(MapOutputName::class);
            $hasSnakeCaseMapper = false;

            if ($globalMapper) {
                foreach ($globalMapper as $attribute) {
                    foreach ($attribute->getArguments() as $argument) {
                        if ($argument === SnakeCaseMapper::class) {
                            $hasSnakeCaseMapper = true;
                            break;
                        }
                    }
                }
            }

            if ($outputMapper) {
                foreach ($outputMapper as $attribute) {
                    foreach ($attribute->getArguments() as $argument) {
                        if ($argument === SnakeCaseMapper::class) {
                            $hasSnakeCaseMapper = true;
                            break;
                        }
                    }
                }
            }

            foreach ($properties as $property) {
                $fields[$hasSnakeCaseMapper ? Str::snake($property->getName()) : $property->getName()] = $property->getType()->getName().'|'.boolval($property->getType()->allowsNull());
            }

            return $fields;
        }

        // Ensure the resource has a toArray method
        if (! $reflection->hasMethod('toArray')) {
            throw new Exception('The resource class must have a toArray method.');
        }

        $method = $reflection->getMethod('toArray');

        // Use PhpParser to parse the `toArray` method
        $parser = (new ParserFactory)->createForHostVersion();
        $code = file_get_contents($method->getFileName());
        $ast = $parser->parse($code);

        // Find the return statement in the toArray method
        $nodeFinder = new NodeFinder;
        $returnNodes = $nodeFinder->findInstanceOf($ast, Return_::class);

        // Traverse the AST to find the fields in the return array
        foreach ($returnNodes as $returnNode) {
            if ($returnNode->expr instanceof Array_) {
                foreach ($returnNode->expr->items as $item) {
                    if ($item instanceof ArrayItem) {
                        // Use the key if it's present
                        $key = $item->key ? $item->key->value : null; // Use .value to access the string

                        // Check if the value is another array
                        if ($item->value instanceof Array_) {
                            $fields[$key] = [
                                'type' => 'array',
                                'items' => [], // Initialize to hold the inner fields
                            ];

                            // Traverse items in the nested array
                            foreach ($item->value->items as $nestedItem) {
                                if ($nestedItem instanceof ArrayItem) {
                                    $nestedKey = $nestedItem->key ? $nestedItem->key->value : null;

                                    // Check for scalar values or nested resources
                                    if ($nestedItem->value instanceof New_) {
                                        // TODO: get this fixed for nested resource classes
                                        // If it's a nested resource, extract fields recursively
                                        //                                        $nestedResourceClass = $nestedItem->value->class->toString();
                                        //                                        $namespace = $this->getFullClassName($nestedResourceClass, $resourceClass);
                                        //                                        $fields[$key]['items'][$nestedKey] =  $this->extractFieldsFromToArray($namespace); // Recursively extract fields
                                        //                                        $fields[$key]['items'][$nestedKey] = $this->extractFieldsFromToArray($nestedResourceClass);
                                    } elseif ($nestedItem->value instanceof String_) {
                                        $fields[$key]['items'][$nestedKey] = 'string'; // Handle string values
                                    } elseif ($nestedItem->value instanceof LNumber) {
                                        $fields[$key]['items'][$nestedKey] = 'integer'; // Handle integer values
                                    } elseif ($nestedItem->value instanceof Array_) {
                                        // Handle nested arrays
                                        $fields[$key]['items'][$nestedKey] = [
                                            'type' => 'array',
                                            'items' => [],
                                        ];
                                        // Traverse the nested array items
                                        foreach ($nestedItem->value->items as $subItem) {
                                            if ($subItem instanceof ArrayItem) {
                                                $subKey = $subItem->key ? $subItem->key->value : null;
                                                $fields[$key]['items'][$nestedKey]['items'][$subKey] = 'unknown'; // Adjust type as needed
                                            }
                                        }
                                    } else {
                                        // Handle other types as necessary
                                        $fields[$key]['items'][$nestedKey] = 'unknown';
                                    }
                                }
                            }
                        } elseif ($item->value instanceof New_) {
                            // If the value is another resource, handle it accordingly
                            $valueClass = $item->value->class->toString();
                            // TODO: get this fixed for nested resource classes
                            //                            $namespace = $this->getFullClassName($valueClass, $resourceClass);
                            //                            $fields[$key] = $this->extractFieldsFromToArray($namespace); // Recursively extract fields
                        } elseif ($item->value instanceof String_) {
                            // If the value is a string, simply store it
                            $fields[$key] = 'string'; // You might want to implement type inference here
                        } elseif ($item->value instanceof LNumber) {
                            // Handle numeric values
                            $fields[$key] = 'integer';
                        } elseif ($item->value instanceof Variable) {
                            // Handle variables if necessary
                            $fields[$key] = 'variable';
                        } else {
                            // Handle other types as needed
                            $fields[$key] = 'unknown';
                        }
                    }
                }
            }
        }

        return $fields;
    }

    private function mapPhpTypeToOpenApiType(string $type): string
    {
        $typeMap = [
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'string' => 'string',
            'array' => 'array',
            'object' => 'object',
            'mixed' => 'string',
            'null' => 'null',
            'resource' => 'string',
            'callable' => 'string',
            'iterable' => 'array',
            'void' => 'null',
        ];

        return $typeMap[$type] ?? 'string';
    }

    private function detectWrapping(string $resourceClass): bool
    {
        $reflection = new ReflectionClass($resourceClass);

        return $reflection->isSubclassOf(\Illuminate\Http\Resources\Json\JsonResource::class);
    }

    private function generateOpenAPIRequestBody(array $validationRules, array $ignoredParameters = []): RequestBody
    {
        foreach ($validationRules as $name => $parameter) {
            if (in_array($name, $ignoredParameters)) {
                unset($validationRules[$name]);
            }
        }

        // Generate properties and required fields for the schema
        $schemaData = $this->generateOpenAPISchema($validationRules);

        // Create the OpenAPI RequestBody with a schema that includes properties and required fields
        return new RequestBody([
            'description' => '',
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => new Schema([
                        'type' => 'object', // The schema must be of type object
                        'properties' => $schemaData['properties'], // Properties must be an object
                        'required' => $schemaData['required'], // Required should be an array of required property names
                    ]),
                ],
            ],
        ]);
    }

    private function generateOpenAPISchema(array $validationRules): array
    {
        $properties = [];
        $requiredFields = [];

        foreach ($validationRules as $name => $parameter) {
            $type = $parameter['type'];
            $format = $parameter['format'] ?? null;
            $description = $parameter['description'] ?? '';

            // Track required fields for the object-level schema
            if ($parameter['required']) {
                $requiredFields[] = $name;
            }

            // Create the basic schema object for this property
            $propertySchema = [
                'type' => $type,
                'description' => $description,
            ];

            if ($format) {
                $propertySchema['format'] = $format;
            }

            // Check if there are nested parameters
            if (! empty($parameter['parameters'])) {
                // Recursively generate the schema for nested parameters
                $propertySchema['type'] = 'object'; // Nested parameters imply an object type
                $nestedSchema = $this->generateOpenAPISchema($parameter['parameters']);
                $propertySchema['properties'] = $nestedSchema['properties'];

                // If the nested object has required fields, include them
                if (! empty($nestedSchema['required'])) {
                    $propertySchema['required'] = $nestedSchema['required'];
                }
            }

            // Assign the generated schema to the property
            $properties[$name] = new Schema($propertySchema);
        }

        return [
            'properties' => $properties,
            'required' => $requiredFields, // Return the list of required fields at the object level
        ];
    }

    private function generateOpenAPIParameters(array $validationRules, string $parentName = '', array $ignoredParameters = []): array
    {
        foreach ($validationRules as $name => $parameter) {
            if (in_array($name, $ignoredParameters)) {
                unset($validationRules[$name]);
            }
        }

        $parameters = [];

        foreach ($validationRules as $name => $parameter) {
            $type = $parameter['type'];
            $format = $parameter['format'] ?? null;
            $description = $parameter['description'] ?? '';
            $required = $parameter['required'] ?? false;

            // Construct the full parameter name (use dot notation for nested parameters)
            $fullName = $parentName ? $parentName.'.'.$name : $name;

            // If the parameter has nested parameters, we need to treat it as an object or array
            if (! empty($parameter['parameters'])) {
                // Recursively generate parameters for nested fields
                $nestedParameters = $this->generateOpenAPIParameters($parameter['parameters'], $fullName);
                $parameters = array_merge($parameters, $nestedParameters);
            } else {
                // Create the schema for the parameter
                $schema = new Schema([
                    'type' => $type,
                ]);

                if ($format) {
                    $schema->format = $format;
                }

                // Create the query parameter object
                $queryParam = new Parameter([
                    'name' => $fullName, // Use the full name, including dot notation if nested
                    'in' => 'query',
                    'required' => $required,
                    'description' => $description,
                    'schema' => $schema,
                ]);

                $parameters[] = $queryParam; // Add this parameter to the list
            }
        }

        return $parameters; // Return an array of query parameters
    }

    private function generatePathItem(string $path, array $route): PathItem
    {
        $pathItem = new PathItem([]);

        foreach ($route['methods'] as $method) {
            $operation = new Operation([
                'responses' => [],
            ]);

            if (isset($route['controller']) && isset($route['action'])) {
                try {
                    $reflection = new ReflectionClass($route['controller']);
                    $methodReflection = $reflection->getMethod($route['action']);

                    // Generate request body
                    $requestBody = $this->generateRequestBody($route['controller'], $route['action']);
                    if ($requestBody) {
                        $operation->requestBody = $requestBody;
                    }

                    // Generate parameters
                    $parameters = $this->generateParameters($methodReflection);
                    if (! empty($parameters)) {
                        $operation->parameters = $parameters;
                    }

                    // Generate responses
                    $responses = $this->generateResponse($methodReflection);
                    if (! empty($responses)) {
                        foreach ($responses as $code => $response) {
                            $operation->responses[$code] = $response;
                        }
                    }
                } catch (Throwable) {
                    // Ignore reflection errors
                }
            }

            // Ensure at least one response exists
            if (empty($operation->responses)) {
                $operation->responses = [
                    '200' => new Response([
                        'description' => '',
                    ]),
                ];
            }

            $method = strtolower($method);
            $pathItem->$method = $operation;
        }

        return $pathItem;
    }

    private function generateRequestBody(string $controller, string $action): ?RequestBody
    {
        // Try smart features first
        $smartRequestBody = $this->generateSmartRequestBody($controller, $action);
        if ($smartRequestBody) {
            return $smartRequestBody;
        }

        try {
            $reflection = new ReflectionClass($controller);
            $methodReflection = $reflection->getMethod($action);

            // Check for request parameter
            foreach ($methodReflection->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type && is_a($type->getName(), Request::class, true)) {
                    return new RequestBody([
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => new Schema([
                                    'type' => 'object',
                                ]),
                            ],
                        ],
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('Error generating request body: '.$e->getMessage());
        }

        return null;
    }

    private function generateResponse(ReflectionMethod $method): array
    {
        // Try smart features first
        $smartResponses = $this->generateSmartResponse($method);
        if (! empty($smartResponses)) {
            return $smartResponses;
        }

        // Default response
        return [
            '200' => new Response([
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => new Schema([
                            'type' => 'object',
                        ]),
                    ],
                ],
            ]),
        ];
    }

    private function generateParameters(ReflectionMethod $method): array
    {
        $parameters = [];

        try {
            $docComment = $method->getDocComment();
            if ($docComment) {
                // Extract @param annotations
                preg_match_all('/@param\s+([^\s]+)\s+\$([^\s]+)(?:\s+(.*))?/', $docComment, $matches);
                foreach ($matches[1] as $i => $type) {
                    $name = $matches[2][$i];
                    $description = $matches[3][$i] ?? '';

                    $parameters[] = new Parameter([
                        'name' => $name,
                        'in' => 'path',
                        'required' => true,
                        'schema' => new Schema([
                            'type' => $this->convertPhpTypeToOpenApiType($type),
                        ]),
                        'description' => $description,
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('Error generating parameters: '.$e->getMessage());
        }

        return $parameters;
    }

    private function convertPhpTypeToOpenApiType(string $phpType): string
    {
        switch (strtolower($phpType)) {
            case 'int':
            case 'integer':
                return 'integer';
            case 'bool':
            case 'boolean':
                return 'boolean';
            case 'float':
            case 'double':
                return 'number';
            case 'array':
                return 'array';
            default:
                return 'string';
        }
    }

    private function processResponseHeaders(array $headers): array
    {
        $result = [];
        
        foreach ($headers as $name => $header) {
            $schema = [];
            
            if (isset($header['type'])) {
                $schema['type'] = $header['type'];
            }
            
            if (isset($header['format'])) {
                $schema['format'] = $header['format'];
            }
            
            $result[$name] = new Header([
                'description' => $header['description'] ?? '',
                'schema' => new Schema($schema),
                'example' => isset($header['example']) ? $this->filterSpatieInternalFields($header['example']) : null,
            ]);
        }
        
        return $result;
    }
    
    /**
     * Filter out Spatie Data internal fields from examples
     * 
     * Rule: Keep the code clean and readable
     */
    private function filterSpatieInternalFields($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        
        $result = [];
        
        foreach ($data as $key => $value) {
            // Skip Spatie internal fields
            if ($key === '_additional' || $key === '_data_context') {
                continue;
            }
            
            if (is_array($value)) {
                $result[$key] = $this->filterSpatieInternalFields($value);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Convert Spatie Data schema format to OpenAPI Schema format
     */
    private function convertSpatieDataSchemaToOpenApi(array $spatieSchema): array
    {
        $openApiSchema = [
            'type' => $spatieSchema['type'] ?? 'object'
        ];

        // Convert properties
        if (isset($spatieSchema['properties'])) {
            $openApiSchema['properties'] = [];
            
            // First pass - identify and remove any internal Spatie fields
            $filteredProperties = [];
            foreach ($spatieSchema['properties'] as $propertyName => $property) {
                // Rule: Keep the code clean and readable - Filter out Spatie internal fields
                if ($propertyName === '_additional' || $propertyName === '_data_context') {
                    continue;
                }
                $filteredProperties[$propertyName] = $property;
            }
            
            // Second pass - convert the filtered properties to OpenAPI format
            foreach ($filteredProperties as $propertyName => $property) {
                $openApiProperty = ['type' => $property['type']];
                
                // Add format if present
                if (isset($property['format']) && $property['format']) {
                    $openApiProperty['format'] = $property['format'];
                }
                
                // Add description if present
                if (isset($property['description']) && $property['description']) {
                    $openApiProperty['description'] = $property['description'];
                }
                
                // Handle nullable properties
                if (isset($property['nullable']) && $property['nullable']) {
                    $openApiProperty['nullable'] = true;
                }
                
                // Handle array items
                if (isset($property['items'])) {
                    $openApiProperty['items'] = $property['items'];
                }
                
                $openApiSchema['properties'][$propertyName] = $openApiProperty;
            }
        }

        // Add required fields
        if (isset($spatieSchema['required'])) {
            $openApiSchema['required'] = $spatieSchema['required'];
        }

        return $openApiSchema;
    }
}
