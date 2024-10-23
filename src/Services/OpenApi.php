<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Exception;
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
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use ReflectionClass;
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
                $middlewares = $data['middlewares'];
                if (is_string($middlewares)) {
                    $middlewares = explode(',', $data['middlewares']);
                }

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

                    $responseSchema = null;
                    if ($response['resource']) {
                        try {
                            $responseSchema = $this->generateOpenAPIResponseSchema($response['resource']);

                            $instance = app()->make($response['resource'], ['resource' => []]);

                            $rulesExist = method_exists($instance, 'toArray');
                            if ($rulesExist) {
                                $actionMethod = new ReflectionMethod($instance, 'toArray');
                                $attributes = $actionMethod->getAttributes();

                                foreach ($attributes as $attribute) {
                                    if ($attribute->getName() === \JkBennemann\LaravelApiDocumentation\Attributes\Parameter::class) {
                                        $name = $attribute->getArguments()['name'] ?? $attribute->getArguments()[0] ?? null;

                                        if (! $name) {
                                            continue;
                                        }

                                        $currentResponseSchema = $responseSchema->getSerializableData();
                                        $currentResponseSchema = json_decode(json_encode($currentResponseSchema), true);
                                        $attributesToOverride = [];

                                        $type = $attribute->getArguments()['type'] ?? $attribute->getArguments()[1] ?? 'string';
                                        if ($type === 'array') {
                                            $type = 'object';
                                        }
                                        if ($type === 'int') {
                                            $type = 'integer';
                                        }

                                        //                                        TODO implement logic
                                        //                                        if ($type !== $this->getPropertyType($currentResponseSchema, $name)) {
                                        //                                            $attributesToOverride['type'] = $type;
                                        //                                        }

                                        if ($attribute->getArguments()['format'] ?? $attribute->getArguments()[3] ?? null) {
                                            $attributesToOverride['format'] = $attribute->getArguments()['format'] ?? $attribute->getArguments()[3] ?? null;
                                        }

                                        if ($attribute->getArguments()['example'] ?? $attribute->getArguments()[6] ?? null) {
                                            $attributesToOverride['example'] = $attribute->getArguments()['example'] ?? $attribute->getArguments()[6] ?? null;
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

    private function extractFieldsFromToArray(string $resourceClass): array
    {
        $reflection = new ReflectionClass($resourceClass);

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

        $fields = [];

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

    private function generateOpenAPIResponseSchema(string $resourceClass)
    {
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
                $properties[$key] = new Schema([
                    'type' => $this->getTypeFromValue($value),
                    'description' => 'Description for '.$key, // Customize as needed
                ]);
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
        $schema = new Schema([
            'type' => 'object',
            'properties' => $properties,
        ]);

        if ($wrap !== null) {
            // If wrapped, create the schema with wrapping
            $schema = new Schema([
                'type' => 'object',
                'properties' => [
                    $wrap => $schema,
                ],
            ]);
        }

        // Create the response object
        return new Response([
            'description' => 'A successful response',
            'content' => [
                'application/json' => [
                    'schema' => $schema,
                ],
            ],
        ]);
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

    private function getFullClassName(string $className, string $currentClass): string
    {
        // Assuming that the current class is namespaced
        $namespace = $this->getNamespace($currentClass);

        // Check if the class name is already fully qualified
        if (strpos($className, '\\') !== false) {
            return $className; // Already fully qualified
        }

        // Otherwise, append the namespace
        return $namespace.'\\'.$className;
    }

    private function getNamespace(string $class): string
    {
        // Assuming you have a way to get the namespace, e.g., using reflection
        $reflection = new \ReflectionClass($class);

        return $reflection->getNamespaceName();
    }

    private function updateResponseArray(&$responseArray, $propertyName, $values)
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

    private function updateSchemaProperty(&$schema, $propertyName, $values)
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
