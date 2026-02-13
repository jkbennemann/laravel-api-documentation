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

        // Also check for inline validation in AST
        if (! $hasFormRequest && $ctx->hasAst()) {
            $hasFormRequest = $this->hasInlineValidation($ctx);
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
