<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Exception;
use Illuminate\Config\Repository;
use Illuminate\Support\Str;
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
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
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

    private array $includedSecuritySchemes = [];

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

        return $this;
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
        $this->openApi->paths = $this->getPaths($routes);

        $this->setSecuritySchemes();

        return $this;
    }

    private function getPaths(array $input): array
    {
        $paths = [];
        $includedSecuritySchemes = [];

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

            $parameters = [];
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
                if (is_array($data['request_parameters'])) {
                    $params = $data['request_parameters'];
                    foreach ($params as $key => $values) {
                        $tmp = [
                            'name' => $key,
                            'in' => 'query',
                            'required' => true,
                            'description' => $values['description'] ?? '',
                            'schema' => [
                                'type' => $values['type'] ?? 'string',
                            ],
                        ];

                        $parameters[] = new Parameter($tmp);
                    }
                } else {
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
            }

            $values = [
                'summary' => $summary,
                'description' => $description,
                'responses' => [    //default response to comply with OpenAPI standard
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
                $middlewares = $data['middlewares'];
                if (is_string($middlewares)) {
                    $middlewares = explode(',', $data['middlewares']);
                }

                if (in_array('auth:jwt', $middlewares)) {
                    $values['security'][] = [
                        'jwt' => [],
                    ];
                    $includedSecuritySchemes[] = 'jwt';
                }

                if (in_array('auth:sanctum', $middlewares)) {
                    $values['security'][] = [
                        'token' => [],
                    ];
                    $includedSecuritySchemes[] = 'token';
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

                    $responseSchema = null;
                    if ($response['resource']) {
                        try {
                            $responseSchema = $this->generateOpenAPIResponseSchema($response);
                            $instance = null;
                            $reflection = new ReflectionClass($response['resource']);
                            if ($reflection->isSubclassOf(Data::class)) {
                                $rulesExist = true;
                                $attributes = $reflection->getAttributes();
                            } else {
                                $instance = app()->make($response['resource'], ['resource' => []]);
                                $rulesExist = method_exists($instance, 'toArray');
                                $actionMethod = new ReflectionMethod($instance, 'toArray');
                                $attributes = $actionMethod->getAttributes();
                            }

                            if ($rulesExist) {
                                foreach ($attributes as $attribute) {
                                    if ($attribute->getName() === \JkBennemann\LaravelApiDocumentation\Attributes\Parameter::class) {
                                        $name = $attribute->getArguments()['name'] ?? $attribute->getArguments()[0] ?? null;

                                        if (! $name) {
                                            continue;
                                        }

                                        $currentResponseSchema = $responseSchema->getSerializableData();
                                        $currentResponseSchema = json_decode(json_encode($currentResponseSchema), true);
                                        $attributesToOverride = [];

                                        if ($attribute->getArguments()['format'] ?? $attribute->getArguments()[3] ?? null) {
                                            $attributesToOverride['format'] = $attribute->getArguments()['format'] ?? $attribute->getArguments()[3] ?? null;
                                        }

                                        if ($attribute->getArguments()['example'] ?? $attribute->getArguments()[6] ?? null) {
                                            $attributesToOverride['example'] = $attribute->getArguments()['example'] ?? $attribute->getArguments()[6] ?? null;
                                        }

                                        if ($attribute->getArguments()['description'] ?? $attribute->getArguments()[4] ?? null) {
                                            $value = $attribute->getArguments()['description'] ?? $attribute->getArguments()[4] ?? null;
                                            if ($value) {
                                                $attributesToOverride['description'] = $value;
                                            }
                                        }

                                        $updated = $this->updateResponseArray($currentResponseSchema, $name, $attributesToOverride);
                                        $responseSchema = new Response($currentResponseSchema);
                                    }

                                    if ($attribute->getName() === Description::class) {
                                        $responseDescription = $attribute->getArguments()['value'] ?? $attribute->getArguments()[0] ?? '';

                                        $currentResponseSchema = $responseSchema->getSerializableData();
                                        $currentResponseSchema = json_decode(json_encode($currentResponseSchema), true);
                                        $updated = $this->updateResponseBaseProperty($currentResponseSchema, 'description', $responseDescription);
                                        $responseSchema = new Response($currentResponseSchema);
                                    }
                                }
                            }
                        } catch (Throwable $e) {
                            $exception = '';

                            continue;
                        }
                    }

                    if (! empty($headers) && $responseSchema) {
                        $currentResponseSchema = $responseSchema->getSerializableData();
                        $currentResponseSchema = json_decode(json_encode($currentResponseSchema), true);

                        $updated = $this->updateResponseBaseProperty($currentResponseSchema, 'headers', $headers);
                        $responseSchema = new Response($currentResponseSchema);
                    }

                    $responses[$code] = $responseSchema;
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

        $includedSecuritySchemes = array_unique($includedSecuritySchemes);
        $this->includedSecuritySchemes = $includedSecuritySchemes;

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
                $fields[$hasSnakeCaseMapper ? Str::snake($property->getName()) : $property->getName()] = $property->getType()->getName() . '|' . boolval($property->getType()->allowsNull());
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
                                        //TODO: get this fixed for nested resource classes
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
                            //TODO: get this fixed for nested resource classes
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

    private function generateOpenAPIResponseSchema(array $response): Response
    {
        $resourceClass = $response['resource'];
        // Extract fields from the Laravel Resource class
        $fields = $this->extractFieldsFromToArray($resourceClass);

        // Detect if the response is wrapped
        $wrap = $this->detectWrapping($resourceClass);

        // Generate the OpenAPI properties
        $properties = [];
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                // Handle nested arrays or resources
                if (isset($value['type'])) {
                    switch ($value['type']) {
                        case 'array':
                            // Handling array types with nested properties
                            if (isset($value['items']) && is_array($value['items'])) {
                                // Create a schema for items
                                $itemProperties = [];
                                foreach ($value['items'] as $itemKey => $itemValue) {
                                    // Assuming itemValue is a scalar type
                                    $itemProperties[$itemKey] = new Schema([
                                        'type' => $this->getTypeFromValue($itemValue),
                                        'description' => 'Description for '.$itemKey, // Customize as needed
                                    ]);
                                }

                                $properties[$key] = new Schema([
                                    'type' => 'array',
                                    'items' => new Schema([
                                        'type' => 'object',
                                        'properties' => $itemProperties,
                                    ]),
                                ]);
                            } else {
                                throw new \InvalidArgumentException("Array type defined without valid items for key: $key");
                            }
                            break;

                        case 'object':
                            // Handling for nested resources (objects)
                            $properties[$key] = new Schema([
                                'type' => 'object',
                                'properties' => $value['properties'] ?? [], // Ensure we have a valid properties array
                            ]);
                            break;

                        default:
                            // For other defined types (string, integer, etc.)
                            $properties[$key] = new Schema([
                                'type' => $value['type'],
                                'description' => 'Description for '.$key, // Customize as needed
                            ]);
                            break;
                    }
                } else {
                    // Handle case when type is not defined but is an array
                    $properties[$key] = new Schema([
                        'type' => 'object', // Defaulting to object, adjust as necessary
                        'properties' => $value,
                    ]);
                }
            } else {
                // Handle scalar types directly
                try {
                    [$value, $isNullable] = explode('|', $value);
                } catch (Exception $e) {
                    $isNullable = false;
                }


                $tmp = [
                    'type' => $this->getTypeFromValue($value),
                    'description' => 'Description for '.$key, // Customize as needed
                ];

                if ($isNullable) {
                    $tmp['nullable'] = true;
                }

                if ($value === 'array') {
                    $tmp['items'] = new Schema([
                        'type' => 'object',
                        'properties' => [],
                        'default' => [],
                    ]);
                }

                $properties[$key] = new Schema($tmp);
            }
        }

        // Handle nested properties (like address)
        if (isset($properties['address']) && isset($fields['address']['items'])) {
            $addressProperties = [];
            foreach ($fields['address']['items'] as $addressKey => $addressValue) {
                $addressProperties[$addressKey] = new Schema([
                    'type' => $this->getTypeFromValue($addressValue),
                    'description' => 'Description for '.$addressKey,
                ]);
            }
            $properties['address'] = new Schema([
                'type' => 'object',
                'properties' => $addressProperties,
            ]);
        }

        // Wrap the response in the given key if necessary
        $data = [
            'type' => 'object',
            'properties' => $properties,
        ];

        $schema = new Schema($data);

        if ($wrap !== null) {
            // If wrapped, create the schema with wrapping
            $data = [
                'type' => 'object',
                'properties' => [
                    $wrap => $schema,
                ],
            ];

            $schema = new Schema($data);
        }

        // Create the response object
        $data = [
            'content' => [
                'application/json' => [
                    'schema' => $schema,
                ],
            ],
        ];

        if ($response['description']) {
            $data['description'] = $response['description'];
        }

        return new Response($data);
    }

    private function detectWrapping(string $resourceClass): mixed
    {
        $reflection = new ReflectionClass($resourceClass);

        // Check for the existence of $wrap property
        if ($reflection->hasProperty('wrap')) {
            $wrapProperty = $reflection->getProperty('wrap');
            $wrapProperty->setAccessible(true);

            return $wrapProperty->getValue($reflection->newInstance(['resource' => []]));
        }

        if ($reflection->isSubclassOf(Data::class)) {
            if ($reflection->hasMethod('defaultWrap')) {
                $parser = (new ParserFactory)->createForHostVersion();
                $method = $reflection->getMethod('defaultWrap');
                $code = file_get_contents($method->getFileName());
                $ast = $parser->parse($code);

                $nodeFinder = new NodeFinder;
                $methodNodes = $nodeFinder->findInstanceOf($ast, ClassMethod::class);

                /** @var ClassMethod $methodNode */
                foreach ($methodNodes as $methodNode) {
                    if ($methodNode->name->name === 'defaultWrap') {
                        $returnNodes = $nodeFinder->findInstanceOf($methodNode, Return_::class);
                        foreach ($returnNodes as $returnNode) {
                            if ($returnNode->expr instanceof String_) {
                                return $returnNode->expr->value;
                            }
                        }
                    }
                }

                return null;
            }

            return null;
        }

        // Default wrap is "data"
        return 'data';
    }

    private function getTypeFromValue($value): string
    {
        // Map Laravel types to OpenAPI types
        switch ($value) {
            case 'string':
                return 'string';
            case 'integer':
            case 'int':
                return 'integer';
            case 'boolean':
                return 'boolean';
            case 'array':
                return 'array';
                // Add more mappings as necessary
            default:
                return 'string'; // Default to string for unrecognized types
        }
    }

    private function updateResponseArray(&$responseArray, $propertyName, $values): bool
    {
        // Check if the response array has the expected structure
        if (isset($responseArray['content']['application/json']['schema'])) {
            // Get the schema
            $schema = &$responseArray['content']['application/json']['schema'];

            // Update the schema properties recursively
            if ($this->updateSchemaProperty($schema, $propertyName, $values)) {
                return true; // Property found and updated
            }
        }

        return false; // Property not found
    }

    private function updateSchemaProperty(&$schema, $propertyName, $values): bool
    {
        // Check if the properties exist in the current schema
        if (isset($schema['properties'][$propertyName])) {
            foreach ($values as $key => $value) {
                if ($value !== null) { // Update only if value is provided
                    // Update the property only if it exists, to avoid overriding with null
                    if (array_key_exists($key, $schema['properties'][$propertyName])) {
                        $schema['properties'][$propertyName][$key] = $value;
                    } else {
                        // Add new key-value pair if it doesn't exist
                        $schema['properties'][$propertyName][$key] = $value;
                    }
                }
            }

            return true; // Property found and updated
        }

        // Check for nested properties
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $key => &$property) {
                if ($this->updateSchemaProperty($property, $propertyName, $values)) {
                    return true; // Property found in nested object
                }
            }
        }

        // Check for items in case of an array
        if (isset($schema['items'])) {
            return $this->updateSchemaProperty($schema['items'], $propertyName, $values);
        }

        return false; // Property not found
    }

    private function updateResponseBaseProperty(&$responseArray, $propertyName, $values): bool
    {

        // Check if the response array has the expected structure
        if (isset($responseArray['content']['application/json']['schema'])) {
            // Get the schema
            $schema = &$responseArray['content']['application/json']['schema'];

            if ($propertyName === 'description') {
                if ($values !== null) {
                    $schema['description'] = $values;

                    return true;
                } else {
                    unset($schema['description']);

                    return true;
                }
            }
        }

        if ($propertyName === 'headers') {
            if (! empty($values)) {
                $responseArray['headers'] = $values;

                return true;
            } else {
                unset($responseArray['headers']);

                return true;
            }
        }

        return false; // Property not found
    }
}
