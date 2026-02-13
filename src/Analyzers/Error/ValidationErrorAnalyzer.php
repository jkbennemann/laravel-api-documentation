<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Error;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class ValidationErrorAnalyzer implements ResponseExtractor
{
    public function __construct(
        private ?ExceptionHandlerSchemaAnalyzer $handlerAnalyzer = null,
    ) {}

    public function extract(AnalysisContext $ctx): array
    {
        if ($ctx->reflectionMethod === null) {
            return [];
        }

        // Check if the method has a FormRequest parameter
        $hasFormRequest = false;
        foreach ($ctx->reflectionMethod->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                try {
                    if (is_subclass_of($type->getName(), FormRequest::class)) {
                        $hasFormRequest = true;
                        break;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        // Also check for inline validation or container-resolved FormRequest in AST
        if (! $hasFormRequest && $ctx->hasAst()) {
            $hasFormRequest = $this->hasInlineValidation($ctx)
                || $this->hasContainerResolvedFormRequest($ctx);
        }

        if (! $hasFormRequest) {
            return [];
        }

        // Check if handler maps ValidationException to a different status code (e.g. 400)
        $statusCode = $this->handlerAnalyzer?->getStatusCodeForException(
            'Illuminate\Validation\ValidationException'
        ) ?? 422;

        return [
            new ResponseResult(
                statusCode: $statusCode,
                schema: $this->validationErrorSchema(),
                description: 'Validation Error',
                source: 'error:validation',
            ),
        ];
    }

    private function hasInlineValidation(AnalysisContext $ctx): bool
    {
        $code = '';
        if ($ctx->astNode !== null) {
            // Simple check: look for validate() or Validator::make() in the AST
            $nodeFinder = new \PhpParser\NodeFinder;
            $calls = $nodeFinder->findInstanceOf(
                $ctx->astNode->stmts ?? [],
                \PhpParser\Node\Expr\MethodCall::class
            );
            foreach ($calls as $call) {
                if ($call->name instanceof \PhpParser\Node\Identifier && $call->name->toString() === 'validate') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect resolve(FormRequest::class) or app(FormRequest::class) in the method body,
     * or $this->method() calls that lead to a FormRequest (up to 3 levels deep).
     */
    private function hasContainerResolvedFormRequest(AnalysisContext $ctx): bool
    {
        if ($ctx->controllerClass() === null) {
            return $this->stmtsContainContainerResolve($ctx->astNode->stmts ?? []);
        }

        return $this->hasValidationInStmts(
            $ctx->astNode->stmts ?? [],
            $ctx->controllerClass(),
            depth: 0,
        );
    }

    private const MAX_DEPTH = 3;

    /**
     * Check if statements contain resolve()/app() calls or $this->method() calls
     * that eventually lead to FormRequest resolution.
     *
     * @param  \PhpParser\Node[]  $stmts
     */
    private function hasValidationInStmts(array $stmts, string $controllerClass, int $depth): bool
    {
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        // Direct resolve()/app() calls
        if ($this->stmtsContainContainerResolve($stmts)) {
            return true;
        }

        // $this->method() calls — check return type or trace into body
        $nodeFinder = new \PhpParser\NodeFinder;
        $methodCalls = $nodeFinder->findInstanceOf($stmts, \PhpParser\Node\Expr\MethodCall::class);

        foreach ($methodCalls as $call) {
            if (! $call->var instanceof \PhpParser\Node\Expr\Variable
                || $call->var->name !== 'this'
                || ! $call->name instanceof \PhpParser\Node\Identifier
            ) {
                continue;
            }

            try {
                $refClass = new \ReflectionClass($controllerClass);
                $methodName = $call->name->toString();
                if (! $refClass->hasMethod($methodName)) {
                    continue;
                }

                // Shortcut: return type is FormRequest
                $returnType = $refClass->getMethod($methodName)->getReturnType();
                if ($returnType instanceof \ReflectionNamedType
                    && ! $returnType->isBuiltin()
                    && is_subclass_of($returnType->getName(), FormRequest::class)
                ) {
                    return true;
                }

                // Trace into helper method body (recursive)
                $declaringClass = $refClass->getMethod($methodName)->getDeclaringClass();
                $fileName = $declaringClass->getFileName();
                if (! $fileName || ! file_exists($fileName)) {
                    continue;
                }

                $helperStmts = $this->getMethodStmts($fileName, $methodName);
                if ($helperStmts !== null && $this->hasValidationInStmts($helperStmts, $controllerClass, $depth + 1)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * Check if statements contain resolve(SomeClass::class) or app(SomeClass::class) calls.
     *
     * @param  \PhpParser\Node[]  $stmts
     */
    private function stmtsContainContainerResolve(array $stmts): bool
    {
        $nodeFinder = new \PhpParser\NodeFinder;
        $funcCalls = $nodeFinder->findInstanceOf($stmts, \PhpParser\Node\Expr\FuncCall::class);

        foreach ($funcCalls as $call) {
            if (! $call->name instanceof \PhpParser\Node\Name) {
                continue;
            }
            $funcName = $call->name->toString();
            if (in_array($funcName, ['resolve', 'app'], true) && ! empty($call->args)) {
                $firstArg = $call->args[0]->value ?? null;
                if ($firstArg instanceof \PhpParser\Node\Expr\ClassConstFetch
                    && $firstArg->name instanceof \PhpParser\Node\Identifier
                    && $firstArg->name->toString() === 'class'
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse a file and extract a specific method's statements.
     *
     * @return \PhpParser\Node[]|null
     */
    private function getMethodStmts(string $filePath, string $methodName): ?array
    {
        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return null;
            }

            $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
            $stmts = $parser->parse($code);
            if ($stmts === null) {
                return null;
            }

            $nodeFinder = new \PhpParser\NodeFinder;
            $classMethods = $nodeFinder->findInstanceOf($stmts, \PhpParser\Node\Stmt\ClassMethod::class);

            foreach ($classMethods as $method) {
                if ($method->name->toString() === $methodName) {
                    return $method->stmts ?? [];
                }
            }
        } catch (\Throwable) {
            // Parsing failed
        }

        return null;
    }

    private function validationErrorSchema(): SchemaObject
    {
        $custom = $this->handlerAnalyzer?->getErrorSchema(422, includeValidationErrors: true);

        return $custom ?? SchemaObject::object(
            properties: [
                'message' => SchemaObject::string(description: 'Error message'),
                'errors' => SchemaObject::object(
                    properties: [
                        'field_name' => SchemaObject::array(SchemaObject::string()),
                    ],
                ),
            ],
            required: ['message', 'errors'],
        );
    }
}
