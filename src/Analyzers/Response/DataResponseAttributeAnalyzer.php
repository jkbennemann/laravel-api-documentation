<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Response;

use Illuminate\Http\Resources\Json\ResourceCollection;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\ResponseBody;
use JkBennemann\LaravelApiDocumentation\Attributes\ResponseHeader;
use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

class DataResponseAttributeAnalyzer implements ResponseExtractor
{
    private TypeMapper $typeMapper;

    private ?JsonResourceAnalyzer $resourceAnalyzer = null;

    public function __construct(
        ?SchemaRegistry $registry = null,
        private readonly array $config = [],
    ) {
        $this->typeMapper = new TypeMapper;

        if ($registry !== null) {
            $this->resourceAnalyzer = new JsonResourceAnalyzer($registry, $this->config);
        }
    }

    public function extract(AnalysisContext $ctx): array
    {
        $results = [];

        // Check for #[DataResponse] attributes
        $dataResponses = $ctx->getAttributes(DataResponse::class);
        foreach ($dataResponses as $attr) {
            $results[] = $this->processDataResponse($attr, $ctx);
        }

        // Check for #[ResponseBody] attributes
        $responseBodies = $ctx->getAttributes(ResponseBody::class);
        foreach ($responseBodies as $attr) {
            $results[] = $this->processResponseBody($attr);
        }

        // Process #[ResponseHeader] attributes and merge into all results
        $responseHeaders = $ctx->getAttributes(ResponseHeader::class);
        if (! empty($responseHeaders) && ! empty($results)) {
            $headerData = $this->processResponseHeaders($responseHeaders);
            $results = array_map(
                fn (ResponseResult $r) => new ResponseResult(
                    statusCode: $r->statusCode,
                    schema: $r->schema,
                    description: $r->description,
                    contentType: $r->contentType,
                    headers: array_merge($r->headers, $headerData),
                    examples: $r->examples,
                    source: $r->source,
                    isCollection: $r->isCollection,
                ),
                $results,
            );
        }

        return $results;
    }

    private function processDataResponse(DataResponse $attr, AnalysisContext $ctx): ResponseResult
    {
        $schema = null;
        $isCollection = $attr->isCollection;

        if (! empty($attr->resource)) {
            if (is_array($attr->resource) && $this->isAssociativeArray($attr->resource)) {
                // Inline schema: ['key' => 'type'] or ['key' => ['type', nullable, desc, example]]
                $schema = $this->buildInlineSchema($attr->resource);
            } else {
                $resourceClass = is_array($attr->resource) ? $attr->resource[0] ?? null : $attr->resource;
                if ($resourceClass !== null) {
                    // Auto-detect collection when not explicitly set
                    if (! $isCollection) {
                        $isCollection = $this->detectCollectionFromContext($ctx, $resourceClass);
                    }

                    // Delegate to JsonResourceAnalyzer for full schema resolution
                    if ($this->resourceAnalyzer !== null
                        && class_exists($resourceClass)
                        && is_subclass_of($resourceClass, \Illuminate\Http\Resources\Json\JsonResource::class)
                    ) {
                        $schema = $this->resourceAnalyzer->analyze($resourceClass);
                    }

                    // Try #[Parameter] attributes on any class (Spatie Data DTOs, etc.)
                    if ($schema === null && $this->resourceAnalyzer !== null && class_exists($resourceClass)) {
                        $schema = $this->resourceAnalyzer->extractSchemaFromParameterAttributes($resourceClass);
                    }

                    // Fall back to TypeMapper for non-JsonResource classes
                    if ($schema === null) {
                        $schema = $this->typeMapper->mapClassName($resourceClass);
                    }
                }
            }
        }

        if ($isCollection && $schema !== null) {
            $schema = SchemaObject::array($schema);
        }

        // Apply resource wrapping (e.g. {"data": ...}) for JsonResource responses
        if ($schema !== null && ! empty($attr->resource)) {
            $resourceClass = is_array($attr->resource) ? $attr->resource[0] ?? null : $attr->resource;
            if ($resourceClass !== null && class_exists($resourceClass)
                && is_subclass_of($resourceClass, \Illuminate\Http\Resources\Json\JsonResource::class)
            ) {
                $wrapKey = JsonResourceAnalyzer::detectWrapKey($resourceClass);
                if ($wrapKey !== null) {
                    $schema = SchemaObject::object(
                        properties: [$wrapKey => $schema],
                        required: [$wrapKey],
                    );
                }
            }
        }

        return new ResponseResult(
            statusCode: $attr->status,
            schema: $schema,
            description: $attr->description ?: $this->descriptionForStatus($attr->status),
            headers: $this->processHeaders($attr->headers),
            source: 'attribute:DataResponse',
            isCollection: $isCollection,
        );
    }

    private function detectCollectionFromContext(AnalysisContext $ctx, string $resourceClass): bool
    {
        // Check AST for Resource::collection(...) static calls
        if ($ctx->hasAst()) {
            $nodeFinder = new NodeFinder;
            $staticCalls = $nodeFinder->findInstanceOf($ctx->astNode->stmts ?? [$ctx->astNode], StaticCall::class);

            $shortName = class_exists($resourceClass) ? (new \ReflectionClass($resourceClass))->getShortName() : null;

            foreach ($staticCalls as $call) {
                if (! $call->name instanceof Identifier || $call->name->toString() !== 'collection') {
                    continue;
                }

                if ($call->class instanceof Name) {
                    $className = $call->class->toString();
                    if ($className === $resourceClass || $className === $shortName) {
                        return true;
                    }
                }
            }
        }

        // Check method return type for ResourceCollection / AnonymousResourceCollection
        $callable = $ctx->reflectionCallable();
        if ($callable !== null) {
            $returnType = $callable->getReturnType();
            if ($returnType instanceof \ReflectionNamedType) {
                $typeName = $returnType->getName();
                if (is_a($typeName, ResourceCollection::class, true)
                    || $typeName === \Illuminate\Http\Resources\Json\AnonymousResourceCollection::class
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function processResponseBody(ResponseBody $attr): ResponseResult
    {
        $schema = null;

        if ($attr->dataClass !== null) {
            $schema = $this->typeMapper->mapClassName($attr->dataClass);
        }

        if ($attr->isCollection && $schema !== null) {
            $schema = SchemaObject::array($schema);
        }

        return new ResponseResult(
            statusCode: $attr->statusCode,
            schema: $schema,
            description: $attr->description ?: $this->descriptionForStatus($attr->statusCode),
            contentType: $attr->contentType,
            examples: $attr->example ? ['default' => $attr->example] : [],
            source: 'attribute:ResponseBody',
            isCollection: $attr->isCollection,
        );
    }

    private function processHeaders(array $headers): array
    {
        $processed = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $processed[$name] = [
                    'description' => $value,
                    'schema' => ['type' => 'string'],
                ];
            } elseif (is_array($value)) {
                // Ensure schema is present
                if (! isset($value['schema'])) {
                    $value['schema'] = ['type' => 'string'];
                }
                $processed[$name] = $value;
            }
        }

        return $processed;
    }

    /**
     * @param  ResponseHeader[]  $headers
     * @return array<string, array{description: string, schema?: array}>
     */
    private function processResponseHeaders(array $headers): array
    {
        $processed = [];
        foreach ($headers as $header) {
            $entry = ['description' => $header->description];
            $entry['schema'] = ['type' => $header->type];
            if ($header->format !== null) {
                $entry['schema']['format'] = $header->format;
            }
            if ($header->example !== null) {
                $entry['example'] = $header->example;
            }
            $entry['required'] = $header->required;
            $processed[$header->name] = $entry;
        }

        return $processed;
    }

    private function isAssociativeArray(array $arr): bool
    {
        foreach (array_keys($arr) as $key) {
            if (is_string($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a schema from an inline associative array definition.
     * Supports: ['key' => 'type'] and ['key' => ['type', nullable, description, example]]
     */
    private function buildInlineSchema(array $resource): SchemaObject
    {
        $properties = [];
        $required = [];

        foreach ($resource as $key => $definition) {
            if (! is_string($key)) {
                continue;
            }

            if (is_string($definition)) {
                // Simple: 'key' => 'string'
                $properties[$key] = $this->typeMapper->mapPhpType($definition);
                $required[] = $key;
            } elseif (is_array($definition)) {
                // Tuple: 'key' => ['type', nullable, description, example]
                $type = $definition[0] ?? 'string';
                $nullable = $definition[1] ?? null;
                $description = $definition[2] ?? null;
                $example = $definition[3] ?? null;

                $propSchema = is_string($type) ? $this->typeMapper->mapPhpType($type) : SchemaObject::string();
                $propSchema = clone $propSchema;

                if ($nullable === true) {
                    $propSchema->nullable = true;
                }
                if ($description !== null) {
                    $propSchema->description = $description;
                }
                if ($example !== null) {
                    $propSchema->example = $example;
                }

                $properties[$key] = $propSchema;

                if ($nullable !== true) {
                    $required[] = $key;
                }
            }
        }

        return SchemaObject::object($properties, ! empty($required) ? $required : null);
    }

    private function descriptionForStatus(int $status): string
    {
        return match ($status) {
            200 => 'Success',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Response',
        };
    }
}
