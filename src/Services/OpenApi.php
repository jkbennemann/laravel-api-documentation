<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Config\Repository;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use openapiphp\openapi\spec\Components;
use openapiphp\openapi\spec\Header;
use openapiphp\openapi\spec\Info;
use openapiphp\openapi\spec\Operation;
use openapiphp\openapi\spec\Parameter;
use openapiphp\openapi\spec\PathItem;
use openapiphp\openapi\spec\RequestBody;
use openapiphp\openapi\spec\Response;
use openapiphp\openapi\spec\Schema;
use openapiphp\openapi\spec\Server;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

class OpenApi
{
    private string $openApiVersion;

    private string $version;

    private string $title;

    private \openapiphp\openapi\spec\OpenApi $openApi;

    private bool $includeVendorRoutes;

    private array $excludedRoutes;

    private array $excludedMethods;

    private array $servers;

    public function __construct(private readonly Repository $configuration)
    {
        $this->openApiVersion = $this->configuration->get('api-documentation.open_api_version', '3.0.2');
        $this->version = $this->configuration->get('api-documentation.version', '1.0.0');
        $this->title = $this->configuration->get('api-documentation.title', $this->configuration->get('app.name', 'API'));
        $this->includeVendorRoutes = $this->configuration->get('api-documentation.include_vendor_routes', false);
        $this->excludedRoutes = $this->configuration->get('api-documentation.excluded_routes', []);
        $this->excludedMethods = $this->configuration->get('api-documentation.excluded_methods', []);
        $this->servers = $this->configuration->get('api-documentation.servers', []);

        $this->openApi = new \openapiphp\openapi\spec\OpenApi([]);

        $this->setBaseInformation();
    }

    public function get(): \openapiphp\openapi\spec\OpenApi
    {
        return $this->openApi;
    }

    public function setBaseInformation(): self
    {
        $this->openApi->openapi = $this->openApiVersion;
        $this->openApi->info = new Info([
            'title' => $this->title,
            'version' => $this->version,
        ]);

        $servers = [];
        foreach ($this->servers as $server) {
            $servers[] = new Server([
                'url' => $server['url'],
                'description' => $server['description'],
            ]);
        }

        if (! empty($servers)) {
            $this->openApi->servers = $servers;
        }

        $this->openApi->components = new Components([
            'securitySchemes' => [
                'jwt' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                ],
                'token' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                ],
            ],
        ]);

        return $this;
    }

    public function processRoutes(array $routes): self
    {
        $this->openApi->paths = $this->getPaths($routes);

        return $this;
    }

    private function getPaths(array $input): array
    {
        $paths = [];
        $parser = (new ParserFactory)->createForHostVersion();
        $traverser = new NodeTraverser;

        foreach ($input as $data) {
            if (! $this->includeVendorRoutes && $data['is_vendor']) {
                continue;
            }
            foreach ($this->excludedRoutes as $excludedRoute) {
                if ($this->matchUri($data['uri'], $excludedRoute)) {
                    continue 2;
                }
            }
            foreach ($this->excludedMethods as $excludedMethod) {
                if ($data['method'] === strtoupper($excludedMethod)) {
                    continue 2;
                }
            }

            $baseInfo = [];
            $description = '';
            $summary = '';
            if ($data['description'] ?? null) {
                $description = $data['description'];
            }
            if ($data['summary'] ?? null) {
                $summary = $data['summary'];
            }

            if (! empty($data['request_parameters'])) {
                $params = explode(',', $data['request_parameters']);
                $parameters = array_map(function ($param) {
                    return new Parameter([
                        'name' => $param,
                        'in' => 'query',
                        'required' => true,
                        'description' => '',
                        'schema' => [
                            'type' => 'string',
                        ],
                    ]);
                }, $params);
            }

            $values = [
                'summary' => $summary,
                'description' => $description,
                'responses' => [
                    '200' => new Response([
                        'description' => '',
                    ]),
                ],
            ];

            if ($data['documentation'] ?? null) {
                $values['externalDocs'] = [
                    'url' => $data['documentation']['url'],
                    'description' => $data['documentation']['description'],
                ];
            }

            if ($data['middlewares'] ?? null) {
                $middlewares = explode(',', $data['middlewares']);

                if (in_array('auth:jwt', $middlewares)) {
                    $values['security'][] = [
                        'jwt' => [],
                    ];
                }

                if (in_array('auth:sanctum', $middlewares)) {
                    $values['security'][] = [
                        'token' => [],
                    ];
                }
            }

            if (isset($parameters)) {
                $baseInfo['parameters'] = $parameters;
            }

            if (! empty($data['parameters'])) {
                if (! in_array($data['method'], ['GET', 'DELETE', 'HEAD'])) {
                    // Generate RequestBody for POST/PUT
                    $requestBody = $this->generateOpenAPIRequestBody($data['parameters']);
                    //TODO: extend for description
                    $values['requestBody'] = $requestBody;
                } else {
                    $parameters = $this->generateOpenAPIParameters($data['parameters']);
                    foreach ($parameters as $parameter) {
                        $baseInfo['parameters'][] = $parameter;
                    }
                }
            }

            if (! empty($data['tags'])) {
                $values['tags'] = $data['tags'];
            }

            //responses
            if (! empty($data['responses'])) {
                $responses = [];
                foreach ($data['responses'] as $code => $response) {
                    $headers = [];
                    foreach ($response['headers'] as $key => $header) {
                        $headers[$key] = new Header([
                            'description' => $header,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ]);
                    }

                    $tmp = [
                        'description' => $response['description'],
                        'headers' => $headers,
                    ];

                    if ($response['resource']) {
                        try {
                            $instance = app()->make($response['resource'], ['resource' => []]);
                            $responseDescription = '';

                            $rulesExist = method_exists($instance, 'toArray');
                            $responseValues = [];
                            if ($rulesExist) {
                                $actionMethod = new ReflectionMethod($instance, 'toArray');
                                $attributes = $actionMethod->getAttributes();

                                //need PHP Parser to get values of array from toArray method here
                                //1. parse method and get values
                                $content = file_get_contents($actionMethod->getFileName());
                                $ast = $parser->parse($content);
                                $stmts = $traverser->traverse($ast);
                                $responseValues = $this->getValues($stmts);
                                $keys = array_keys($responseValues); //keys from toArray method

                                //2. TODO: override fetched values in case $attributes is not empty for those keys
                                foreach ($attributes as $attribute) {
                                    if ($attribute->getName() === \JkBennemann\LaravelApiDocumentation\Attributes\Parameter::class) {
                                        $name = $attribute->getArguments()['name'] ?? $attribute->getArguments()[0] ?? null;

                                        if (! $name) {
                                            continue;
                                        }

                                        $type = $attribute->getArguments()['type'] ?? $attribute->getArguments()[1] ?? 'string';
                                        if ($type === 'array') {
                                            $type = 'object';
                                        }
                                        if ($type === 'int') {
                                            $type = 'integer';
                                        }

                                        $m = [
                                            'type' => $type,
                                            'description' => $attribute->getArguments()['description'] ?? $attribute->getArguments()[4] ?? '',
                                        ];

                                        if ($attribute->getArguments()['format'] ?? $attribute->getArguments()[3] ?? null) {
                                            $m['format'] = $attribute->getArguments()['format'] ?? $attribute->getArguments()[3] ?? null;
                                        }

                                        if ($attribute->getArguments()['example'] ?? $attribute->getArguments()[6] ?? null) {
                                            $m['example'] = $attribute->getArguments()['example'] ?? $attribute->getArguments()[6] ?? null;
                                        }

                                        $responseValues[$name] = $m;
                                    }

                                    if ($attribute->getName() === Description::class) {
                                        $responseDescription = $attribute->getArguments()['value'] ?? $attribute->getArguments()[0] ?? '';
                                    }
                                }

                                if ($instance::$wrap) {
                                    $responseValues = [
                                        $instance::$wrap => [
                                            'type' => 'object',
                                            'properties' => $responseValues,
                                        ],
                                    ];
                                }
                            }

                            $tmp['content'] = [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => $responseValues,
                                        'description' => $responseDescription,
                                    ],
                                ],
                            ];
                        } catch (Throwable) {
                            $t = [];

                            if (is_array($response['resource'])) {
                                foreach ($response['resource'] as $key => $value) {
                                    $t[$key] = [
                                        'type' => 'string',
                                    ];
                                }
                                if (! empty($t)) {
                                    $tmp['content'] = [
                                        'application/json' => [
                                            'schema' => [
                                                'type' => 'object',
                                                'properties' => $t,
                                            ],
                                        ],
                                    ];
                                }
                            }
                        }
                    }

                    $responses[$code] = new Response($tmp);
                }
                $values['responses'] = $responses;
            }

            if (isset($paths[$this->replacePlaceholdersForOpenApi('/'.$data['uri'])])) {
                $item = $paths[$this->replacePlaceholdersForOpenApi('/'.$data['uri'])];
            } else {
                $item = new PathItem($baseInfo);
            }

            $operation = new Operation($values);

            $item->{strtolower($data['method'])} = $operation;

            $paths[$this->replacePlaceholdersForOpenApi('/'.$data['uri'])] = $item;
        }

        return $paths;
    }

    private function replacePlaceholdersForOpenApi($url): string
    {
        // Regular expression to match content inside curly braces
        return preg_replace('/\{([^\/]+?)\}/', ':$1', $url);
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

    private function getValues(array $stmts): array
    {
        $values = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $st) {
                    if ($st instanceof Class_) {
                        foreach ($st->stmts as $s) {
                            if ($s instanceof ClassMethod) {
                                if ($s->name->name === 'toArray') {
                                    foreach ($s->stmts as $stmt) {
                                        if ($stmt instanceof Return_) {
                                            $value = $stmt->expr->items;

                                            foreach ($value as $val) {
                                                $values[$val->key->value] = [
                                                    'type' => 'string',
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                }
            }
        }

        return $values;
    }

    private function getItems(string $name, array $items, array &$required): array
    {
        //is last child element
        if (empty($items['parameters'])) {
            $params[$name] = [
                'type' => $items['type'] ?? null,
                'description' => $items['description'] ?? '',
                'deprecated' => $items['deprecated'],
                'items' => [],
            ];

            if ($items['format'] !== null) {
                $params[$name]['format'] = $items['format'];
            }

            if ($items['required']) {
                $required[] = $name;
            }

            if ($items['type'] === null) {
                unset($params[$name]['type']);
            }

            return $params;
        }

        if ($items['required'] ?? false) {
            $required[] = $name;
        }

        return [
            'type' => $items['type'],
            'description' => $items['description'],
            'deprecated' => $items['deprecated'],
            'required' => $items['required'] ?? false,
            'items' => $this->getItems($name, $items['parameters'], $required),
        ];
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

    private function generateOpenAPIRequestBody(array $validationRules): RequestBody
    {
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

        return $requestBody;
    }

    private function generateOpenAPIParameters(array $validationRules, string $parentName = ''): array
    {
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
}
