<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Emission;

use JkBennemann\LaravelApiDocumentation\Attributes\AdditionalDocumentation;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\PathParameter;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\Schema\ExampleGenerator;
use JkBennemann\LaravelApiDocumentation\Schema\PhpDocParser;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;

class PathBuilder
{
    private SchemaBuilder $schemaBuilder;

    private ?PhpDocParser $phpDocParser = null;

    public function __construct(
        ?ExampleGenerator $exampleGenerator = null,
        private readonly ?SchemaRegistry $schemaRegistry = null,
    ) {
        $this->schemaBuilder = new SchemaBuilder($exampleGenerator);
    }

    public function setPhpDocParser(PhpDocParser $parser): void
    {
        $this->phpDocParser = $parser;
    }

    /**
     * Build an OpenAPI operation for a route.
     *
     * @param  ParameterResult[]  $queryParams
     * @param  array<int, ResponseResult>  $responses
     * @param  array<int, array<string, string[]>>|null  $security
     * @return array<string, mixed>
     */
    public function buildOperation(
        AnalysisContext $ctx,
        ?SchemaResult $requestBody,
        array $queryParams,
        array $responses,
        ?array $security = null,
    ): array {
        $operation = [];

        // Tags
        $tag = $ctx->getAttribute(Tag::class);
        if ($tag instanceof Tag && $tag->value !== null) {
            $operation['tags'] = is_array($tag->value) ? $tag->value : [$tag->value];
        } else {
            $operation['tags'] = [$this->inferTag($ctx)];
        }

        // Summary
        $summary = $ctx->getAttribute(Summary::class);
        if ($summary instanceof Summary && $summary->value !== '') {
            $operation['summary'] = $summary->value;
        } else {
            $operation['summary'] = $this->inferSummary($ctx);
        }

        // Description (attribute takes precedence, then PHPDoc fallback)
        $description = $ctx->getAttribute(Description::class);
        if ($description instanceof Description && $description->value !== '') {
            $operation['description'] = $description->value;
        } elseif ($this->phpDocParser !== null && $ctx->reflectionMethod !== null) {
            $phpDocDescription = $this->phpDocParser->getDescription($ctx->reflectionMethod);
            if ($phpDocDescription !== null) {
                $operation['description'] = $phpDocDescription;
            }
        }

        // Operation ID
        $operation['operationId'] = $this->buildOperationId($ctx);

        // Parameters (path + query)
        $parameters = $this->buildPathParameters($ctx);
        foreach ($queryParams as $qp) {
            $parameters[] = $this->buildParameter($qp);
        }
        if (! empty($parameters)) {
            $operation['parameters'] = $parameters;
        }

        // Request body
        if ($requestBody !== null) {
            $operation['requestBody'] = $this->buildRequestBody($requestBody);
        }

        // Responses
        $operation['responses'] = $this->buildResponses($responses);

        // Security
        if ($security !== null) {
            $operation['security'] = $security;
        }

        // Deprecated detection
        if ($this->isDeprecated($ctx)) {
            $operation['deprecated'] = true;

            $deprecationMessage = $this->getDeprecationMessage($ctx);
            if ($deprecationMessage !== null) {
                $existingDesc = $operation['description'] ?? '';
                $suffix = "**Deprecated:** {$deprecationMessage}";
                $operation['description'] = $existingDesc !== ''
                    ? $existingDesc."\n\n".$suffix
                    : $suffix;
            }
        }

        // External docs
        $additionalDoc = $ctx->getAttribute(AdditionalDocumentation::class);
        if ($additionalDoc instanceof AdditionalDocumentation) {
            $operation['externalDocs'] = [
                'url' => $additionalDoc->url,
                'description' => $additionalDoc->description ?: 'Additional documentation',
            ];
        }

        return $operation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPathParameters(AnalysisContext $ctx): array
    {
        $parameters = [];

        // From route path
        foreach ($ctx->route->pathParameters as $name => $type) {
            $schema = SchemaObject::string();

            // Check for PathParameter attributes
            $pathParams = $ctx->getAttributes(PathParameter::class);
            foreach ($pathParams as $attr) {
                if ($attr->name === $name) {
                    $schema = new SchemaObject(
                        type: $attr->type,
                        format: $attr->format,
                        example: $attr->example,
                    );

                    $parameters[] = [
                        'name' => $name,
                        'in' => 'path',
                        'required' => true,
                        'description' => $attr->description ?: null,
                        'schema' => $this->schemaBuilder->build($schema),
                    ];

                    continue 2;
                }
            }

            // Apply route where() constraints to infer type/pattern
            $constraint = $ctx->route->pathConstraints[$name] ?? null;
            if ($constraint !== null) {
                $schema = $this->applyConstraintToSchema($schema, $constraint);
            }

            // Apply route model binding key inference
            $bindingSchema = $this->inferBindingKeySchema($ctx, $name);
            if ($bindingSchema !== null && $constraint === null) {
                $schema = $bindingSchema;
            }

            // Generate description for path parameters
            $description = null;
            $bindingField = $ctx->route->bindingFields[$name] ?? null;
            if ($bindingField !== null) {
                $description = "Resolved by {$bindingField}";
            } else {
                $description = $this->generatePathParameterDescription($name);
            }

            $parameters[] = array_filter([
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'description' => $description,
                'schema' => $this->schemaBuilder->build($schema),
            ], fn ($v) => $v !== null);
        }

        return array_map(fn ($p) => array_filter($p, fn ($v) => $v !== null), $parameters);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParameter(ParameterResult $param): array
    {
        $data = [
            'name' => $param->name,
            'in' => $param->in,
            'required' => $param->required,
            'schema' => $this->schemaBuilder->build($param->schema),
        ];

        if ($param->description !== null) {
            $data['description'] = $param->description;
        }

        if ($param->example !== null) {
            $data['example'] = $param->example;
        }

        if ($param->deprecated) {
            $data['deprecated'] = true;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestBody(SchemaResult $result): array
    {
        $body = [
            'required' => $result->required,
            'content' => $this->schemaBuilder->wrapInContentWithExamples(
                $result->schema,
                $result->examples,
                $result->contentType ?? 'application/json',
            ),
        ];

        if ($result->description !== null) {
            $body['description'] = $result->description;
        }

        return $body;
    }

    /**
     * @param  array<int, ResponseResult>  $responses
     * @return array<int|string, array<string, mixed>>
     */
    private function buildResponses(array $responses): array
    {
        if (empty($responses)) {
            return [
                '200' => ['description' => 'Success'],
            ];
        }

        $built = [];

        foreach ($responses as $result) {
            $schema = $result->schema;

            // Register response schemas as components for $ref deduplication
            if ($schema !== null && $this->schemaRegistry !== null && $schema->ref === null) {
                $name = $this->deriveResponseSchemaName($result->source ?? '', $result->statusCode);
                if ($name !== null) {
                    $registered = $this->schemaRegistry->registerIfComplex($name, $schema);
                    if ($registered instanceof SchemaObject && $registered !== $schema) {
                        $schema = $registered;
                        $result = $result->withSchema($schema);
                    }
                }
            }

            $response = ['description' => $result->description ?: 'Response'];

            if ($result->schema !== null) {
                $response['content'] = $this->schemaBuilder->wrapInContentWithExamples(
                    $result->schema,
                    $result->examples,
                    $result->contentType,
                );
            }

            if (! empty($result->headers)) {
                $response['headers'] = $result->headers;
            }

            $built[(string) $result->statusCode] = $response;
        }

        return $built;
    }

    private function inferTag(AnalysisContext $ctx): string
    {
        $controller = $ctx->controllerClass();
        if ($controller === null) {
            return $this->inferTagFromUri($ctx->route->uri);
        }

        $baseName = class_basename($controller);

        // Remove common suffixes
        $tag = preg_replace('/(Controller|Resource|Handler)$/', '', $baseName);

        // For invokable controllers, strip action prefixes to group by resource
        if ($ctx->actionMethod() === '__invoke') {
            $tag = preg_replace('/^(List|Get|Create|Update|Delete|Store|Show|Destroy|Find|Search|Fetch|Remove|Index|Edit|Handle)/', '', $tag);
        }

        // Convert PascalCase to spaced words
        $tag = preg_replace('/([a-z])([A-Z])/', '$1 $2', $tag);

        // If stripping left nothing, fall back to URI segment
        if ($tag === '' || $tag === null) {
            $tag = $this->inferTagFromUri($ctx->route->uri);
        }

        return $tag ?: 'default';
    }

    private function inferTagFromUri(string $uri): string
    {
        $segments = explode('/', trim($uri, '/'));

        // Walk backwards to find the first non-parameter segment
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (! str_starts_with($segments[$i], '{')) {
                return ucfirst(str_replace(['-', '_'], ' ', $segments[$i]));
            }
        }

        return 'default';
    }

    private function inferSummary(AnalysisContext $ctx): string
    {
        $action = $ctx->actionMethod();
        if ($action === null) {
            return '';
        }

        $resource = $this->inferResourceName($ctx);
        $method = $ctx->route->httpMethod();

        return match ($action) {
            'index' => "List {$resource}",
            'show' => "Get {$resource} details",
            'store', 'create' => "Create {$resource}",
            'update' => "Update {$resource}",
            'destroy', 'delete' => "Delete {$resource}",
            '__invoke' => $this->inferFromMethodAndUri($method, $ctx->route->uri),
            default => ucfirst(preg_replace('/([a-z])([A-Z])/', '$1 $2', $action))." {$resource}",
        };
    }

    private function inferResourceName(AnalysisContext $ctx): string
    {
        $controller = $ctx->controllerClass();
        if ($controller !== null) {
            $baseName = class_basename($controller);
            $name = preg_replace('/(Controller|Resource|Handler)$/', '', $baseName);

            // For invokable controllers, strip action prefixes
            if ($ctx->actionMethod() === '__invoke') {
                $name = preg_replace('/^(List|Get|Create|Update|Delete|Store|Show|Destroy|Find|Search|Fetch|Remove|Index|Edit|Handle)/', '', $name);
            }

            // Convert PascalCase to spaced words
            $name = trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $name));

            if ($name !== '') {
                return $name;
            }
        }

        // Fallback: extract from URI
        return $this->inferResourceFromUri($ctx->route->uri);
    }

    private function inferResourceFromUri(string $uri): string
    {
        $segments = explode('/', trim($uri, '/'));

        // Walk backwards to find the first non-parameter segment
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (! str_starts_with($segments[$i], '{')) {
                return ucfirst(str_replace(['-', '_'], ' ', $segments[$i]));
            }
        }

        return 'resource';
    }

    private function inferFromMethodAndUri(string $method, string $uri): string
    {
        $segments = explode('/', trim($uri, '/'));
        $lastSegment = end($segments);

        if (str_starts_with($lastSegment, '{')) {
            $lastSegment = prev($segments) ?: 'resource';
        }

        $resource = ucfirst(str_replace(['-', '_'], ' ', $lastSegment));

        return match (strtoupper($method)) {
            'GET' => "Get {$resource}",
            'POST' => "Create {$resource}",
            'PUT', 'PATCH' => "Update {$resource}",
            'DELETE' => "Delete {$resource}",
            default => "{$method} {$resource}",
        };
    }

    /**
     * Infer the schema type for a path parameter based on route model binding.
     * Checks explicit binding fields ({user:slug}) and model's getRouteKeyName().
     */
    private function inferBindingKeySchema(AnalysisContext $ctx, string $paramName): ?SchemaObject
    {
        // Determine the binding field name
        $bindingField = $ctx->route->bindingFields[$paramName] ?? null;

        $modelClass = null;

        // If no explicit binding field, try to detect from the model's getRouteKeyName()
        if ($bindingField === null && $ctx->hasReflection()) {
            $modelClass = $this->detectModelForParameter($ctx, $paramName);
            if ($modelClass !== null) {
                try {
                    $model = (new \ReflectionClass($modelClass))->newInstanceWithoutConstructor();
                    $routeKey = $model->getRouteKeyName();
                    if ($routeKey !== 'id') {
                        $bindingField = $routeKey;
                    }
                } catch (\Throwable) {
                    // Skip
                }
            }
        }

        if ($bindingField === null) {
            // No explicit binding field — check model traits for UUID/ULID, else default to integer
            if ($modelClass !== null) {
                return $this->inferSchemaFromModelKey($modelClass);
            }

            return null;
        }

        // Map common key names to schema types
        return match (true) {
            str_contains($bindingField, 'uuid') => new SchemaObject(type: 'string', format: 'uuid'),
            str_contains($bindingField, 'ulid') => new SchemaObject(type: 'string', format: 'ulid'),
            str_contains($bindingField, 'slug') => new SchemaObject(type: 'string', pattern: '[a-z0-9-]+'),
            $bindingField === 'id' => SchemaObject::integer(),
            str_ends_with($bindingField, '_id') => SchemaObject::integer(),
            str_contains($bindingField, 'email') => new SchemaObject(type: 'string', format: 'email'),
            default => SchemaObject::string(),
        };
    }

    private function inferSchemaFromModelKey(string $modelClass): SchemaObject
    {
        try {
            $traits = class_uses_recursive($modelClass);
            if (in_array('Illuminate\Database\Eloquent\Concerns\HasUuids', $traits, true)) {
                return new SchemaObject(type: 'string', format: 'uuid');
            }
            if (in_array('Illuminate\Database\Eloquent\Concerns\HasUlids', $traits, true)) {
                return new SchemaObject(type: 'string', format: 'ulid');
            }

            $model = (new \ReflectionClass($modelClass))->newInstanceWithoutConstructor();
            if ($model->getKeyType() === 'int') {
                return SchemaObject::integer();
            }
        } catch (\Throwable) {
            // Skip
        }

        return SchemaObject::integer();
    }

    private function generatePathParameterDescription(string $paramName): string
    {
        // Convert snake_case/camelCase to readable words
        $readable = str_replace('_', ' ', $paramName);
        $readable = preg_replace('/([a-z])([A-Z])/', '$1 $2', $readable);
        $readable = strtolower($readable);

        return 'The '.trim($readable).' ID';
    }

    /**
     * Detect the Eloquent model class type-hinted for a parameter name on the controller action.
     */
    private function detectModelForParameter(AnalysisContext $ctx, string $paramName): ?string
    {
        if ($ctx->reflectionMethod === null) {
            return null;
        }

        foreach ($ctx->reflectionMethod->getParameters() as $param) {
            if ($param->getName() !== $paramName) {
                continue;
            }

            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $className = $type->getName();
                if (class_exists($className) && is_subclass_of($className, 'Illuminate\Database\Eloquent\Model')) {
                    return $className;
                }
            }
        }

        return null;
    }

    private function isDeprecated(AnalysisContext $ctx): bool
    {
        if (! $ctx->hasReflection()) {
            return false;
        }

        $callable = $ctx->reflectionCallable();
        if ($callable === null) {
            return false;
        }

        // Check PHP 8.4+ #[\Deprecated] attribute on method/function
        foreach ($callable->getAttributes() as $attr) {
            if ($attr->getName() === 'Deprecated') {
                return true;
            }
        }

        // Check PHPDoc @deprecated tag on method/function
        if ($this->phpDocParser !== null && $this->phpDocParser->isDeprecatedCallable($callable)) {
            // Check for @notDeprecated exemption on method level
            if ($callable instanceof \ReflectionMethod && $this->hasNotDeprecated($callable)) {
                return false;
            }

            return true;
        }

        // Fallback: check docblock directly for @deprecated without full parser
        if ($this->phpDocParser === null) {
            $docComment = $callable->getDocComment();
            if ($docComment !== false && str_contains($docComment, '@deprecated')) {
                if ($callable instanceof \ReflectionMethod && $this->hasNotDeprecated($callable)) {
                    return false;
                }

                return true;
            }
        }

        // Check class-level deprecation (only for controller methods)
        if ($callable instanceof \ReflectionMethod) {
            $class = $callable->getDeclaringClass();

            // Check @notDeprecated exemption first
            if ($this->hasNotDeprecated($callable)) {
                return false;
            }

            // Check PHP 8.4+ #[\Deprecated] on class
            foreach ($class->getAttributes() as $attr) {
                if ($attr->getName() === 'Deprecated') {
                    return true;
                }
            }

            // Check class-level PHPDoc @deprecated
            if ($this->phpDocParser !== null && $this->phpDocParser->isClassDeprecated($class)) {
                return true;
            }

            // Fallback: class docblock
            if ($this->phpDocParser === null) {
                $classDoc = $class->getDocComment();
                if ($classDoc !== false && str_contains($classDoc, '@deprecated')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasNotDeprecated(\ReflectionMethod $method): bool
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return false;
        }

        return str_contains($docComment, '@notDeprecated');
    }

    private function getDeprecationMessage(AnalysisContext $ctx): ?string
    {
        if ($this->phpDocParser === null || ! $ctx->hasReflection()) {
            return null;
        }

        $callable = $ctx->reflectionCallable();
        if ($callable === null) {
            return null;
        }

        // Try method/function level first
        $message = $this->phpDocParser->getDeprecationMessage($callable);
        if ($message !== null) {
            return $message;
        }

        // Try class level
        if ($callable instanceof \ReflectionMethod) {
            return $this->phpDocParser->getDeprecationMessage($callable->getDeclaringClass());
        }

        return null;
    }

    private function applyConstraintToSchema(SchemaObject $schema, string $constraint): SchemaObject
    {
        // Numeric patterns → integer type
        if (preg_match('/^\[?[0-9\-]+\]?\+?$/', $constraint) || $constraint === '\d+' || $constraint === '[0-9]+') {
            return new SchemaObject(type: 'integer', example: $schema->example);
        }

        // UUID pattern
        if (str_contains($constraint, '[0-9a-f]') && str_contains($constraint, '-')) {
            return new SchemaObject(type: 'string', format: 'uuid', example: $schema->example);
        }

        // ULID pattern (26 alphanumeric)
        if ($constraint === '[0-7][0-9A-HJKMNP-TV-Z]{25}' || str_contains(strtolower($constraint), 'ulid')) {
            return new SchemaObject(type: 'string', format: 'ulid', example: $schema->example);
        }

        // Alphabetic-only
        if ($constraint === '[a-zA-Z]+' || $constraint === '[a-z]+' || $constraint === '[A-Z]+') {
            $schema->pattern = $constraint;

            return $schema;
        }

        // Slug pattern
        if ($constraint === '[a-z0-9-]+' || $constraint === '[a-zA-Z0-9-]+' || $constraint === '[a-z0-9_-]+') {
            $schema->pattern = $constraint;

            return $schema;
        }

        // Any other constraint → set as pattern on string schema
        $schema->pattern = $constraint;

        return $schema;
    }

    private function buildOperationId(AnalysisContext $ctx): string
    {
        $method = strtolower($ctx->route->httpMethod());
        $uri = str_replace(['/', '{', '}', '?'], ['.', '', '', ''], $ctx->route->uri);
        $uri = trim($uri, '.');

        return $method.'.'.$uri;
    }

    private function deriveResponseSchemaName(string $source, int $statusCode): ?string
    {
        return match (true) {
            $source === 'error:authentication' => 'AuthenticationError',
            $source === 'error:authorization' => 'AuthorizationError',
            $source === 'error:not_found' => 'NotFoundError',
            $source === 'error:validation' => 'ValidationError',
            $source === 'error:rate_limit' => 'RateLimitError',
            str_starts_with($source, 'analyzer:exception') => 'Error'.$statusCode,
            $source === 'config:error_responses' => 'Error'.$statusCode,
            default => null, // already registered or unique per-endpoint
        };
    }
}
