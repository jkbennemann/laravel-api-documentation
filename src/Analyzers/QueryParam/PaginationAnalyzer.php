<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam;

use JkBennemann\LaravelApiDocumentation\Contracts\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeFinder;

class PaginationAnalyzer implements QueryParameterExtractor
{
    public function extract(AnalysisContext $ctx): array
    {
        if (! $ctx->hasAst()) {
            return [];
        }

        $paginationType = $this->detectPagination($ctx->astNode);

        if ($paginationType === null) {
            return [];
        }

        return match ($paginationType) {
            'paginate', 'simplePaginate' => $this->standardPaginationParams(),
            'cursorPaginate' => $this->cursorPaginationParams(),
            default => [],
        };
    }

    private function detectPagination(Node $node): ?string
    {
        $nodeFinder = new NodeFinder;
        $calls = $nodeFinder->findInstanceOf($node->stmts ?? [$node], MethodCall::class);

        $paginationMethods = ['paginate', 'simplePaginate', 'cursorPaginate'];

        foreach ($calls as $call) {
            if ($call->name instanceof Node\Identifier) {
                $name = $call->name->toString();
                if (in_array($name, $paginationMethods, true)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @return ParameterResult[]
     */
    private function standardPaginationParams(): array
    {
        return [
            ParameterResult::query(
                name: 'page',
                schema: SchemaObject::integer(),
                required: false,
                description: 'Page number',
                example: 1,
            ),
            ParameterResult::query(
                name: 'per_page',
                schema: SchemaObject::integer(),
                required: false,
                description: 'Number of items per page',
                example: 15,
            ),
        ];
    }

    /**
     * @return ParameterResult[]
     */
    private function cursorPaginationParams(): array
    {
        return [
            ParameterResult::query(
                name: 'cursor',
                schema: SchemaObject::string(),
                required: false,
                description: 'Pagination cursor',
            ),
            ParameterResult::query(
                name: 'per_page',
                schema: SchemaObject::integer(),
                required: false,
                description: 'Number of items per page',
                example: 15,
            ),
        ];
    }
}
