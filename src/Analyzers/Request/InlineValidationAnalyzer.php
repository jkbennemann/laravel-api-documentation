<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Request;

use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

class InlineValidationAnalyzer implements RequestBodyExtractor
{
    private ValidationRuleMapper $ruleMapper;

    public function __construct(array $config = [])
    {
        $this->ruleMapper = new ValidationRuleMapper($config);
    }

    public function extract(AnalysisContext $ctx): ?SchemaResult
    {
        if (! $ctx->hasAst()) {
            return null;
        }

        $rules = $this->findValidateCalls($ctx->astNode);
        if (empty($rules)) {
            return null;
        }

        $schema = $this->ruleMapper->mapAllRules($rules);
        $contentType = $this->ruleMapper->hasFileUpload($rules)
            ? 'multipart/form-data'
            : 'application/json';

        return new SchemaResult(
            schema: $schema,
            description: 'Request body',
            contentType: $contentType,
            source: 'inline_validation',
        );
    }

    /**
     * Find $request->validate([...]) or $this->validate($request, [...]) calls.
     *
     * @return array<string, string[]|string>
     */
    private function findValidateCalls(Node $node): array
    {
        $nodeFinder = new NodeFinder;

        $methodCalls = $nodeFinder->findInstanceOf(
            $node->stmts ?? [$node],
            MethodCall::class
        );

        foreach ($methodCalls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $methodName = $call->name->toString();

            // $request->validate([...])
            if ($methodName === 'validate' && ! empty($call->args)) {
                $rulesArg = $call->args[0]->value ?? null;
                if ($rulesArg instanceof Array_) {
                    return $this->extractRulesFromArray($rulesArg);
                }
            }

            // Validator::make($data, [...])
            if ($methodName === 'make' && count($call->args) >= 2) {
                $rulesArg = $call->args[1]->value ?? null;
                if ($rulesArg instanceof Array_) {
                    return $this->extractRulesFromArray($rulesArg);
                }
            }
        }

        // Also check static calls: Validator::make(...)
        $staticCalls = $nodeFinder->findInstanceOf(
            $node->stmts ?? [$node],
            Node\Expr\StaticCall::class
        );

        foreach ($staticCalls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            if ($call->name->toString() === 'make' && count($call->args) >= 2) {
                $rulesArg = $call->args[1]->value ?? null;
                if ($rulesArg instanceof Array_) {
                    return $this->extractRulesFromArray($rulesArg);
                }
            }
        }

        return [];
    }

    /**
     * @return array<string, string[]|string>
     */
    private function extractRulesFromArray(Array_ $array): array
    {
        $rules = [];

        foreach ($array->items as $item) {
            if (! $item instanceof ArrayItem || $item->key === null) {
                continue;
            }

            $key = null;
            if ($item->key instanceof String_) {
                $key = $item->key->value;
            }

            if ($key === null) {
                continue;
            }

            if ($item->value instanceof String_) {
                $rules[$key] = $item->value->value;
            } elseif ($item->value instanceof Array_) {
                $values = [];
                foreach ($item->value->items as $subItem) {
                    if ($subItem instanceof ArrayItem && $subItem->value instanceof String_) {
                        $values[] = $subItem->value->value;
                    }
                }
                if (! empty($values)) {
                    $rules[$key] = $values;
                }
            }
        }

        return $rules;
    }
}
