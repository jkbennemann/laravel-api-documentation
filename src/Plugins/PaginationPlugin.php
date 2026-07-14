<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use Illuminate\Http\Resources\Json\ResourceCollection;
use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeFinder;

class PaginationPlugin implements OperationTransformer, Plugin
{
    public function name(): string
    {
        return 'pagination';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addOperationTransformer($this, 40);
    }

    public function priority(): int
    {
        return 40;
    }

    public function transform(array $operation, AnalysisContext $ctx): array
    {
        $paginationType = $this->detectPagination($ctx);
        if ($paginationType === null) {
            return $operation;
        }

        // Wrap the 200 response in a pagination envelope
        if (isset($operation['responses']['200']['content']['application/json']['schema'])) {
            $existingSchema = $operation['responses']['200']['content']['application/json']['schema'];

            $wrapped = $this->applyPaginationEnvelope($existingSchema, $paginationType);
            if ($wrapped !== null) {
                $operation['responses']['200']['content']['application/json']['schema'] = $wrapped;
            }
        }

        return $operation;
    }

    private function detectPagination(AnalysisContext $ctx): ?string
    {
        // 1. Direct paginate() calls in AST
        if ($ctx->hasAst()) {
            $direct = $this->detectDirectPaginateCalls($ctx->astNode);
            if ($direct !== null) {
                return $direct;
            }
        }

        // 2. Return type is ResourceCollection or subclass
        if ($this->hasCollectionReturnType($ctx)) {
            return 'paginate';
        }

        // 3. Method name heuristic: any method call containing "paginate"
        if ($ctx->hasAst() && $this->detectPaginateInMethodNames($ctx->astNode)) {
            return 'paginate';
        }

        return null;
    }

    private function detectDirectPaginateCalls(Node $node): ?string
    {
        $nodeFinder = new NodeFinder;
        $calls = $nodeFinder->findInstanceOf($node->stmts ?? [$node], MethodCall::class);

        $methods = ['paginate', 'simplePaginate', 'cursorPaginate'];

        foreach ($calls as $call) {
            if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), $methods, true)) {
                return $call->name->toString();
            }
        }

        return null;
    }

    private function hasCollectionReturnType(AnalysisContext $ctx): bool
    {
        $callable = $ctx->reflectionCallable();
        if ($callable === null) {
            return false;
        }

        $returnType = $callable->getReturnType();
        if (! $returnType instanceof \ReflectionNamedType) {
            return false;
        }

        $typeName = $returnType->getName();

        return is_a($typeName, ResourceCollection::class, true)
            || $typeName === \Illuminate\Http\Resources\Json\AnonymousResourceCollection::class;
    }

    private function detectPaginateInMethodNames(Node $node): bool
    {
        $nodeFinder = new NodeFinder;
        $calls = $nodeFinder->findInstanceOf($node->stmts ?? [$node], MethodCall::class);

        foreach ($calls as $call) {
            if ($call->name instanceof Node\Identifier
                && stripos($call->name->toString(), 'paginate') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply pagination envelope, handling three schema shapes:
     * 1. Raw array: {type: "array", items: {...}}
     * 2. Resource-wrapped array: {type: "object", properties: {data: {type: "array", items: ...}}}
     * 3. Resource-wrapped single object: {type: "object", properties: {data: {type: "object", ...}}}
     *
     * @return array<string, mixed>|null
     */
    private function applyPaginationEnvelope(array $schema, string $type): ?array
    {
        // Case 1: Raw array — wrap in full pagination envelope
        if (($schema['type'] ?? '') === 'array' || isset($schema['items'])) {
            return $this->buildPaginationEnvelope($schema, $type);
        }

        // Case 2 & 3: Resource-wrapped schema with properties.data
        if (($schema['type'] ?? '') === 'object' && isset($schema['properties']['data'])) {
            // Skip if already has pagination metadata (prevent double-wrap)
            if (isset($schema['properties']['links']) || isset($schema['properties']['meta'])) {
                return null;
            }

            $dataSchema = $schema['properties']['data'];

            // Case 3: data is a single object — wrap it in an array first
            if (($dataSchema['type'] ?? '') === 'object' || isset($dataSchema['properties'])) {
                $schema['properties']['data'] = [
                    'type' => 'array',
                    'items' => $dataSchema,
                ];
            }

            // Add links and meta as siblings to data
            return $this->addPaginationMetadata($schema, $type);
        }

        return null;
    }

    /**
     * Build a full pagination envelope wrapping a raw data schema.
     *
     * @return array<string, mixed>
     */
    private function buildPaginationEnvelope(array $dataSchema, string $type): array
    {
        $envelope = [
            'type' => 'object',
            'properties' => [
                'data' => $dataSchema,
            ],
            'required' => ['data'],
        ];

        return $this->addPaginationMetadata($envelope, $type);
    }

    /**
     * Add links and meta properties to an existing schema object.
     *
     * @return array<string, mixed>
     */
    private function addPaginationMetadata(array $schema, string $type): array
    {
        // Build property schemas via SchemaObject so nullability is rendered for the
        // active OpenAPI version (3.1 → type: [T, "null"]; 3.0 → nullable: true).
        $prop = static fn (string $t, ?string $format = null, bool $nullable = false, mixed $example = null): array => (new SchemaObject(
            type: $t,
            format: $format,
            example: $example,
            nullable: $nullable,
        ))->jsonSerialize();

        if ($type === 'cursorPaginate') {
            // Laravel's cursor paginator resource response also carries a `links` block
            // (first/last are always null for cursor pagination; prev/next are URLs).
            $schema['properties']['links'] = [
                'type' => 'object',
                'properties' => [
                    'first' => $prop('string', 'uri', true),
                    'last' => $prop('string', 'uri', true),
                    'prev' => $prop('string', 'uri', true),
                    'next' => $prop('string', 'uri', true, 'https://example.com/api/resource?cursor=eyJpZCI6MTV9'),
                ],
            ];
            $schema['properties']['meta'] = [
                'type' => 'object',
                'properties' => [
                    'path' => $prop('string', null, false, 'https://example.com/api/resource'),
                    'per_page' => $prop('integer', null, false, 15),
                    'next_cursor' => $prop('string', null, true, 'eyJpZCI6MTUsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0'),
                    'prev_cursor' => $prop('string', null, true),
                ],
            ];
        } else {
            $schema['properties']['links'] = [
                'type' => 'object',
                'properties' => [
                    'first' => $prop('string', 'uri', true, 'https://example.com/api/resource?page=1'),
                    'last' => $prop('string', 'uri', true, 'https://example.com/api/resource?page=10'),
                    'prev' => $prop('string', 'uri', true),
                    'next' => $prop('string', 'uri', true, 'https://example.com/api/resource?page=2'),
                ],
            ];
            $schema['properties']['meta'] = [
                'type' => 'object',
                'properties' => [
                    'current_page' => $prop('integer', null, false, 1),
                    'from' => $prop('integer', null, true, 1),
                    'last_page' => $prop('integer', null, false, 10),
                    'per_page' => $prop('integer', null, false, 15),
                    'to' => $prop('integer', null, true, 15),
                    'total' => $prop('integer', null, false, 150),
                    'path' => $prop('string', null, false, 'https://example.com/api/resource'),
                ],
            ];
        }

        return $schema;
    }
}
