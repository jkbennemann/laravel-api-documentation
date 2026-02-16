<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

class SpatieQueryBuilderPlugin implements Plugin, QueryParameterExtractor
{
    public function name(): string
    {
        return 'spatie-query-builder';
    }

    public function boot(PluginRegistry $registry): void
    {
        if (! class_exists(\Spatie\QueryBuilder\QueryBuilder::class)) {
            return;
        }

        $registry->addQueryExtractor($this, 90);
    }

    public function priority(): int
    {
        return 45;
    }

    public function extract(AnalysisContext $ctx): array
    {
        if (! $ctx->hasAst()) {
            return [];
        }

        $params = [];
        $nodeFinder = new NodeFinder;

        // Find QueryBuilder::for() calls and chain
        $calls = $nodeFinder->findInstanceOf($ctx->astNode->stmts ?? [], MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $methodName = $call->name->toString();

            if ($methodName === 'allowedFilters') {
                $params = array_merge($params, $this->extractFilters($call));
            }

            if ($methodName === 'allowedSorts') {
                $params = array_merge($params, $this->extractSorts($call));
            }

            if ($methodName === 'allowedIncludes') {
                $params = array_merge($params, $this->extractIncludes($call));
            }

            if ($methodName === 'allowedFields') {
                $params = array_merge($params, $this->extractFields($call));
            }
        }

        return $params;
    }

    /**
     * @return ParameterResult[]
     */
    private function extractFilters(MethodCall $call): array
    {
        $params = [];
        $names = $this->extractStringArgs($call);

        foreach ($names as $name) {
            $params[] = ParameterResult::query(
                name: "filter[{$name}]",
                schema: SchemaObject::string(),
                required: false,
                description: "Filter by {$name}",
            );
        }

        return $params;
    }

    /**
     * @return ParameterResult[]
     */
    private function extractSorts(MethodCall $call): array
    {
        $names = $this->extractStringArgs($call);

        if (empty($names)) {
            return [];
        }

        $sortValues = [];
        foreach ($names as $name) {
            $sortValues[] = $name;
            $sortValues[] = "-{$name}";
        }

        return [
            ParameterResult::query(
                name: 'sort',
                schema: new SchemaObject(type: 'string', enum: $sortValues),
                required: false,
                description: 'Sort by field. Prefix with - for descending order.',
            ),
        ];
    }

    /**
     * @return ParameterResult[]
     */
    private function extractIncludes(MethodCall $call): array
    {
        $names = $this->extractStringArgs($call);

        if (empty($names)) {
            return [];
        }

        return [
            ParameterResult::query(
                name: 'include',
                schema: new SchemaObject(type: 'string', enum: $names),
                required: false,
                description: 'Include related resources. Comma-separated.',
            ),
        ];
    }

    /**
     * @return ParameterResult[]
     */
    private function extractFields(MethodCall $call): array
    {
        $names = $this->extractStringArgs($call);

        if (empty($names)) {
            return [];
        }

        return [
            ParameterResult::query(
                name: 'fields',
                schema: SchemaObject::string(),
                required: false,
                description: 'Select specific fields. Available: '.implode(', ', $names),
            ),
        ];
    }

    /**
     * @return string[]
     */
    private function extractStringArgs(MethodCall $call): array
    {
        $names = [];

        foreach ($call->args as $arg) {
            if ($arg->value instanceof String_) {
                $names[] = $arg->value->value;
            }

            // Handle array argument: allowedFilters(['name', 'email'])
            if ($arg->value instanceof Node\Expr\Array_) {
                foreach ($arg->value->items as $item) {
                    if ($item instanceof Node\ArrayItem && $item->value instanceof String_) {
                        $names[] = $item->value->value;
                    }
                }
            }
        }

        return $names;
    }
}
