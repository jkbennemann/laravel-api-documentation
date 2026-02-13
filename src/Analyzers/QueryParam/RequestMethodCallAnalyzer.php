<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam;

use JkBennemann\LaravelApiDocumentation\Contracts\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ParameterResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

class RequestMethodCallAnalyzer implements QueryParameterExtractor
{
    /** @var array<string, string> Method name → OpenAPI type mapping */
    private const METHOD_TYPE_MAP = [
        'integer' => 'integer',
        'float' => 'number',
        'boolean' => 'boolean',
        'string' => 'string',
        'str' => 'string',
        'input' => 'string',
        'query' => 'string',
        'date' => 'date-time',
        'enum' => 'enum',
        'collect' => 'array',
    ];

    public function extract(AnalysisContext $ctx): array
    {
        if (! $ctx->hasAst()) {
            return [];
        }

        // Only activate for GET/HEAD/DELETE routes (query parameter routes)
        $httpMethod = $ctx->route->httpMethod();
        if (! in_array($httpMethod, ['GET', 'HEAD', 'DELETE'], true)) {
            return [];
        }

        $pathParamNames = array_keys($ctx->route->pathParameters);

        $nodeFinder = new NodeFinder;
        $calls = $nodeFinder->findInstanceOf($ctx->astNode->stmts ?? [], MethodCall::class);

        $params = [];
        $seenNames = [];

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $methodName = $call->name->toString();
            if (! isset(self::METHOD_TYPE_MAP[$methodName])) {
                continue;
            }

            // Check that the call is on a $request variable
            if (! $this->isRequestVariable($call->var)) {
                continue;
            }

            // First argument must be a string literal (parameter name)
            if (empty($call->args) || ! $call->args[0]->value instanceof String_) {
                continue;
            }

            $paramName = $call->args[0]->value->value;

            // Skip if this is a path parameter or already seen
            if (in_array($paramName, $pathParamNames, true) || isset($seenNames[$paramName])) {
                continue;
            }

            $seenNames[$paramName] = true;

            $schema = $this->buildSchema($methodName, $call);
            $default = $this->extractDefault($methodName, $call);

            if ($default !== null) {
                $schema->default = $default;
            }

            $params[] = ParameterResult::query(
                name: $paramName,
                schema: $schema,
                required: false,
            );
        }

        return $params;
    }

    private function isRequestVariable(Node $node): bool
    {
        return $node instanceof Variable && $node->name === 'request';
    }

    private function buildSchema(string $methodName, MethodCall $call): SchemaObject
    {
        $type = self::METHOD_TYPE_MAP[$methodName];

        if ($type === 'enum') {
            return $this->buildEnumSchema($call);
        }

        if ($type === 'date-time') {
            return SchemaObject::string('date-time');
        }

        if ($type === 'number') {
            return SchemaObject::number('double');
        }

        if ($type === 'array') {
            return new SchemaObject(type: 'array', items: SchemaObject::string());
        }

        return match ($type) {
            'integer' => SchemaObject::integer(),
            'boolean' => SchemaObject::boolean(),
            default => SchemaObject::string(),
        };
    }

    private function buildEnumSchema(MethodCall $call): SchemaObject
    {
        // Second argument should be the enum class
        if (count($call->args) < 2) {
            return SchemaObject::string();
        }

        $enumArg = $call->args[1]->value;

        // Handle Enum::class constant fetch
        $enumClass = null;
        if ($enumArg instanceof ClassConstFetch && $enumArg->class instanceof Name) {
            $enumClass = $enumArg->class->toString();
        } elseif ($enumArg instanceof String_) {
            $enumClass = $enumArg->value;
        }

        if ($enumClass === null || ! enum_exists($enumClass)) {
            return SchemaObject::string();
        }

        try {
            $reflection = new \ReflectionEnum($enumClass);
            if (! $reflection->isBacked()) {
                return SchemaObject::string();
            }

            $cases = $enumClass::cases();
            /** @var \BackedEnum[] $cases */
            $values = array_map(fn (\BackedEnum $case) => $case->value, $cases);
            $backingType = (string) $reflection->getBackingType();

            return new SchemaObject(
                type: $backingType === 'int' ? 'integer' : 'string',
                enum: $values,
            );
        } catch (\Throwable) {
            return SchemaObject::string();
        }
    }

    private function extractDefault(string $methodName, MethodCall $call): mixed
    {
        // For enum, the default is the 3rd argument; for others it's the 2nd
        $defaultIndex = $methodName === 'enum' ? 2 : 1;

        if (count($call->args) <= $defaultIndex) {
            return null;
        }

        $defaultNode = $call->args[$defaultIndex]->value;

        if ($defaultNode instanceof Int_) {
            return $defaultNode->value;
        }

        if ($defaultNode instanceof Float_) {
            return $defaultNode->value;
        }

        if ($defaultNode instanceof String_) {
            return $defaultNode->value;
        }

        if ($defaultNode instanceof Node\Expr\ConstFetch) {
            $name = strtolower($defaultNode->name->toString());

            return match ($name) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => null,
            };
        }

        return null;
    }
}
