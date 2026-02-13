<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Error;

use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use PhpParser\Node;
use PhpParser\NodeFinder;

class AuthorizationErrorAnalyzer implements ResponseExtractor
{
    public function __construct(
        private ?ExceptionHandlerSchemaAnalyzer $handlerAnalyzer = null,
    ) {}

    public function extract(AnalysisContext $ctx): array
    {
        $abilities = [];

        // Check for authorization middleware (can:update,Post)
        foreach ($ctx->route->middleware as $mw) {
            if (str_starts_with($mw, 'can:')) {
                $parts = explode(',', substr($mw, 4));
                $abilities[] = trim($parts[0]);
            } elseif ($mw === 'authorize') {
                $abilities[] = null; // Has authorization but ability unknown
            }
        }

        // Also check AST for $this->authorize() or Gate calls
        if ($ctx->hasAst()) {
            $abilities = array_merge($abilities, $this->extractAbilitiesFromAst($ctx));
        }

        // Check if any FormRequest parameter has authorize() that can return false
        if (empty($abilities) && $ctx->reflectionMethod !== null) {
            if ($this->detectFormRequestAuthorization($ctx)) {
                $abilities[] = null;
            }
        }

        if (empty($abilities)) {
            return [];
        }

        // Build a descriptive message from the ability names
        $knownAbilities = array_filter($abilities);
        $description = 'Forbidden';
        if (! empty($knownAbilities)) {
            $abilityList = implode(', ', array_unique($knownAbilities));
            $description = "Forbidden — requires ability: {$abilityList}";
        }

        return [
            new ResponseResult(
                statusCode: 403,
                schema: $this->forbiddenSchema(),
                description: $description,
                source: 'error:authorization',
            ),
        ];
    }

    /**
     * Extract ability names from AST authorization calls.
     *
     * @return array<int, string|null>
     */
    private function extractAbilitiesFromAst(AnalysisContext $ctx): array
    {
        $abilities = [];
        $nodeFinder = new NodeFinder;

        // Check method calls: $this->authorize('update', $post), $this->can('delete')
        $calls = $nodeFinder->findInstanceOf(
            $ctx->astNode->stmts ?? [],
            Node\Expr\MethodCall::class
        );

        foreach ($calls as $call) {
            if ($call->name instanceof Node\Identifier) {
                $name = $call->name->toString();
                if (in_array($name, ['authorize', 'authorizeForUser', 'can', 'cannot'])) {
                    $abilities[] = $this->extractAbilityArg($call->args);
                }
            }
        }

        // Check static calls: Gate::allows('view'), Gate::authorize('update'), Gate::denies('delete')
        $staticCalls = $nodeFinder->findInstanceOf(
            $ctx->astNode->stmts ?? [],
            Node\Expr\StaticCall::class
        );

        foreach ($staticCalls as $call) {
            if ($call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
                $class = $call->class->toString();
                if (in_array($class, ['Gate', 'Illuminate\Support\Facades\Gate'])) {
                    $abilities[] = $this->extractAbilityArg($call->args);
                }
            }
        }

        // Check function calls: abort_unless(Gate::allows(...)), policy($user)->update()
        $funcCalls = $nodeFinder->findInstanceOf(
            $ctx->astNode->stmts ?? [],
            Node\Expr\FuncCall::class
        );

        foreach ($funcCalls as $call) {
            if ($call->name instanceof Node\Name) {
                $funcName = $call->name->toString();
                if (in_array($funcName, ['authorize', 'can'])) {
                    $abilities[] = $this->extractAbilityArg($call->args);
                }
            }
        }

        return $abilities;
    }

    /**
     * Extract the ability name from the first argument of an authorization call.
     */
    private function extractAbilityArg(array $args): ?string
    {
        if (empty($args)) {
            return null;
        }

        $first = $args[0] instanceof Node\Arg ? $args[0]->value : $args[0];

        if ($first instanceof Node\Scalar\String_) {
            return $first->value;
        }

        return null;
    }

    private function detectFormRequestAuthorization(AnalysisContext $ctx): bool
    {
        if ($ctx->reflectionMethod === null) {
            return false;
        }

        foreach ($ctx->reflectionMethod->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();
            if (! class_exists($className)) {
                continue;
            }

            // Check if it's a FormRequest subclass
            if (! is_subclass_of($className, 'Illuminate\Foundation\Http\FormRequest')) {
                continue;
            }

            // Check if the FormRequest has an authorize() method that's not just "return true"
            try {
                $formReflection = new \ReflectionClass($className);
                if (! $formReflection->hasMethod('authorize')) {
                    continue;
                }

                $authorizeMethod = $formReflection->getMethod('authorize');
                // Only consider if declared on this class (not the base FormRequest)
                if ($authorizeMethod->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                // The method exists and is overridden — authorization may fail
                return true;
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    private function forbiddenSchema(): SchemaObject
    {
        $custom = $this->handlerAnalyzer?->getErrorSchema(403);

        return $custom ?? SchemaObject::object(
            properties: [
                'message' => new SchemaObject(
                    type: 'string',
                    example: 'This action is unauthorized.',
                ),
            ],
            required: ['message'],
        );
    }
}
