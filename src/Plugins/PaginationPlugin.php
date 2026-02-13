<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use Illuminate\Http\Resources\Json\ResourceCollection;
use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
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
        if ($type === 'cursorPaginate') {
            $schema['properties']['meta'] = [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'example' => 'https://example.com/api/resource'],
                    'per_page' => ['type' => 'integer', 'example' => 15],
                    'next_cursor' => ['type' => 'string', 'nullable' => true, 'example' => 'eyJpZCI6MTUsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0'],
                    'prev_cursor' => ['type' => 'string', 'nullable' => true, 'example' => null],
                ],
            ];
        } else {
            $schema['properties']['links'] = [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => 'string', 'format' => 'uri', 'example' => 'https://example.com/api/resource?page=1'],
                    'last' => ['type' => 'string', 'format' => 'uri', 'nullable' => true, 'example' => 'https://example.com/api/resource?page=10'],
                    'prev' => ['type' => 'string', 'format' => 'uri', 'nullable' => true, 'example' => null],
                    'next' => ['type' => 'string', 'format' => 'uri', 'nullable' => true, 'example' => 'https://example.com/api/resource?page=2'],
                ],
            ];
            $schema['properties']['meta'] = [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer', 'example' => 1],
                    'from' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                    'last_page' => ['type' => 'integer', 'example' => 10],
                    'per_page' => ['type' => 'integer', 'example' => 15],
                    'to' => ['type' => 'integer', 'nullable' => true, 'example' => 15],
                    'total' => ['type' => 'integer', 'example' => 150],
                    'path' => ['type' => 'string', 'example' => 'https://example.com/api/resource'],
                ],
            ];
        }

        return $schema;
    }
}
