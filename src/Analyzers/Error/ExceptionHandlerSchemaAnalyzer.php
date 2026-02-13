<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Error;

use Illuminate\Contracts\Debug\ExceptionHandler;
use JkBennemann\LaravelApiDocumentation\Data\HandlerAnalysisResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\HttpFoundation\Response;

class ExceptionHandlerSchemaAnalyzer
{
    private ?HandlerAnalysisResult $result = null;

    private bool $analyzed = false;

    public function hasCustomHandler(): bool
    {
        $this->ensureAnalyzed();

        return $this->result !== null;
    }

    /**
     * Build the full envelope schema for a given status code.
     */
    public function getErrorSchema(int $statusCode, bool $includeValidationErrors = false): ?SchemaObject
    {
        $this->ensureAnalyzed();

        if ($this->result === null) {
            return null;
        }

        $properties = $this->result->baseProperties;
        $required = $this->result->baseRequired;

        // Apply status-specific message example
        if (isset($properties['message']) && isset($this->result->statusMessages[$statusCode])) {
            $properties['message'] = new SchemaObject(
                type: 'string',
                example: $this->result->statusMessages[$statusCode],
            );
        }

        // Add conditional properties (errors/details) for validation responses
        if ($includeValidationErrors && ! empty($this->result->conditionalProperties)) {
            foreach ($this->result->conditionalProperties as $key => $schema) {
                $properties[$key] = $schema;
            }
        }

        return SchemaObject::object(
            properties: $properties,
            required: ! empty($required) ? $required : null,
        );
    }

    /**
     * Look up the HTTP status code for an exception class from the handler's mapping.
     */
    public function getStatusCodeForException(string $exceptionClass): ?int
    {
        $this->ensureAnalyzed();

        if ($this->result === null) {
            return null;
        }

        return $this->result->statusCodeMapping[$exceptionClass] ?? null;
    }

    private function ensureAnalyzed(): void
    {
        if ($this->analyzed) {
            return;
        }

        $this->analyzed = true;
        $this->result = $this->analyze();
    }

    private function analyze(): ?HandlerAnalysisResult
    {
        try {
            $handler = app(ExceptionHandler::class);
        } catch (\Throwable) {
            return null;
        }

        $reflection = new \ReflectionClass($handler);

        // Skip if it's the default Laravel handler without customization
        if (! $this->hasCustomRender($reflection)) {
            return null;
        }

        $fileName = $reflection->getFileName();
        if (! $fileName || ! file_exists($fileName)) {
            return null;
        }

        $code = file_get_contents($fileName);
        if ($code === false) {
            return null;
        }

        try {
            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $stmts = $parser->parse($code);
        } catch (\Throwable) {
            return null;
        }

        if ($stmts === null) {
            return null;
        }

        $finder = new NodeFinder;
        $classes = $finder->findInstanceOf($stmts, Stmt\Class_::class);

        if (empty($classes)) {
            return null;
        }

        $classNode = $classes[0];

        // Extract use statements for class name resolution
        $useMap = $this->extractUseStatements($stmts);

        // Extract status code mapping
        $statusCodeMapping = $this->extractStatusCodeMapping($reflection, $classNode, $useMap);

        // Extract render method structure
        [$baseProperties, $baseRequired] = $this->extractRenderStructure($classNode);

        if (empty($baseProperties)) {
            return null;
        }

        // Extract conditional properties (errors/details from helper methods)
        $conditionalProperties = $this->extractConditionalProperties($classNode, $baseProperties);

        // Extract status messages from getMessage()/getErrorMessage() methods
        $statusMessages = $this->extractStatusMessages($classNode);

        return new HandlerAnalysisResult(
            baseProperties: $baseProperties,
            baseRequired: $baseRequired,
            conditionalProperties: $conditionalProperties,
            statusCodeMapping: $statusCodeMapping,
            statusMessages: $statusMessages,
        );
    }

    /**
     * Check if the handler has a custom render() or renderable() method.
     */
    private function hasCustomRender(\ReflectionClass $reflection): bool
    {
        $defaultHandlerClass = \Illuminate\Foundation\Exceptions\Handler::class;

        if ($reflection->getName() === $defaultHandlerClass) {
            return false;
        }

        // Check for render() override
        if ($reflection->hasMethod('render')) {
            $renderMethod = $reflection->getMethod('render');
            if ($renderMethod->getDeclaringClass()->getName() !== $defaultHandlerClass) {
                return true;
            }
        }

        // Check for register() method (Laravel 8+ renderable callbacks)
        if ($reflection->hasMethod('register')) {
            $registerMethod = $reflection->getMethod('register');
            if ($registerMethod->getDeclaringClass()->getName() !== $defaultHandlerClass) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract use statements from the file for resolving short class names to FQCNs.
     *
     * @param  Stmt[]  $stmts
     * @return array<string, string> Short name → FQCN
     */
    private function extractUseStatements(array $stmts): array
    {
        $map = [];
        $finder = new NodeFinder;

        $uses = $finder->findInstanceOf($stmts, Stmt\Use_::class);
        foreach ($uses as $use) {
            foreach ($use->uses as $useUse) {
                $fqcn = $useUse->name->toString();
                $alias = $useUse->alias?->toString() ?? $useUse->name->getLast();
                $map[$alias] = $fqcn;
            }
        }

        return $map;
    }

    /**
     * Extract exception → status code mapping from $exceptionStatusCode property and ERROR_RESPONSES constant.
     *
     * @param  array<string, string>  $useMap
     * @return array<class-string, int>
     */
    private function extractStatusCodeMapping(\ReflectionClass $reflection, Stmt\Class_ $classNode, array $useMap): array
    {
        $mapping = [];

        // Read from class constants (ERROR_RESPONSES)
        $mapping = array_merge($mapping, $this->extractMappingFromConstants($classNode));

        // Read from property ($exceptionStatusCode) — child overrides parent
        $mapping = array_merge($mapping, $this->extractMappingFromProperty($classNode));

        // Resolve short class names to FQCNs using use statements
        $mapping = $this->resolveClassNames($mapping, $useMap);

        // Resolve Response::HTTP_* constants to integers
        return $this->resolveHttpConstants($mapping);
    }

    /**
     * Resolve short class names in mapping keys to their FQCNs.
     *
     * @param  array<string, mixed>  $mapping
     * @param  array<string, string>  $useMap
     * @return array<string, mixed>
     */
    private function resolveClassNames(array $mapping, array $useMap): array
    {
        $resolved = [];

        foreach ($mapping as $className => $statusCode) {
            // If it's already a FQCN (contains backslash), keep it
            if (str_contains($className, '\\')) {
                $resolved[$className] = $statusCode;

                continue;
            }

            // Try to resolve via use statements
            if (isset($useMap[$className])) {
                $resolved[$useMap[$className]] = $statusCode;
            } else {
                $resolved[$className] = $statusCode;
            }
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMappingFromConstants(Stmt\Class_ $classNode): array
    {
        $mapping = [];

        foreach ($classNode->getConstants() as $constNode) {
            $name = $constNode->consts[0]->name->toString();
            if ($name !== 'ERROR_RESPONSES') {
                continue;
            }

            $value = $constNode->consts[0]->value;
            if (! $value instanceof Expr\Array_) {
                continue;
            }

            foreach ($value->items as $item) {
                if (! $item instanceof ArrayItem || $item->key === null) {
                    continue;
                }

                $exceptionClass = $this->resolveClassConstFetch($item->key);
                $statusCode = $this->resolveStatusValue($item->value);

                if ($exceptionClass !== null && $statusCode !== null) {
                    $mapping[$exceptionClass] = $statusCode;
                }
            }
        }

        return $mapping;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMappingFromProperty(Stmt\Class_ $classNode): array
    {
        $mapping = [];
        $finder = new NodeFinder;

        $properties = $finder->findInstanceOf($classNode->stmts, Stmt\Property::class);

        foreach ($properties as $prop) {
            if ($prop->props[0]->name->toString() !== 'exceptionStatusCode') {
                continue;
            }

            $default = $prop->props[0]->default;
            if (! $default instanceof Expr\Array_) {
                continue;
            }

            foreach ($default->items as $item) {
                if (! $item instanceof ArrayItem || $item->key === null) {
                    continue;
                }

                $exceptionClass = $this->resolveClassConstFetch($item->key);
                $statusCode = $this->resolveStatusValue($item->value);

                if ($exceptionClass !== null && $statusCode !== null) {
                    $mapping[$exceptionClass] = $statusCode;
                }
            }
        }

        return $mapping;
    }

    /**
     * Resolve a class constant fetch (e.g. ValidationException::class) to the FQCN string.
     */
    private function resolveClassConstFetch(Node $node): ?string
    {
        if ($node instanceof Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'class') {
            return $node->class->toString();
        }

        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        return null;
    }

    /**
     * Resolve a status code value — integer literal or Response::HTTP_* constant.
     */
    private function resolveStatusValue(Node $node): int|string|null
    {
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }

        // Response::HTTP_BAD_REQUEST etc.
        if ($node instanceof Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier) {
            $constName = $node->name->toString();
            if (str_starts_with($constName, 'HTTP_')) {
                return $constName;
            }
        }

        return null;
    }

    /**
     * Resolve HTTP_* constant names to their integer values.
     *
     * @param  array<string, int|string>  $mapping
     * @return array<string, int>
     */
    private function resolveHttpConstants(array $mapping): array
    {
        $resolved = [];

        // Build a lookup for Response::HTTP_* constants
        $httpConstants = [];
        $refClass = new \ReflectionClass(Response::class);
        foreach ($refClass->getConstants() as $name => $value) {
            if (str_starts_with($name, 'HTTP_') && is_int($value)) {
                $httpConstants[$name] = $value;
            }
        }

        foreach ($mapping as $exception => $status) {
            if (is_int($status)) {
                $resolved[$exception] = $status;
            } elseif (is_string($status) && isset($httpConstants[$status])) {
                $resolved[$exception] = $httpConstants[$status];
            }
        }

        return $resolved;
    }

    /**
     * Extract the JSON structure from the render() method.
     *
     * @return array{0: array<string, SchemaObject>, 1: string[]}
     */
    private function extractRenderStructure(Stmt\Class_ $classNode): array
    {
        $finder = new NodeFinder;
        $methods = $finder->findInstanceOf($classNode->stmts, Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            if ($method->name->toString() !== 'render') {
                continue;
            }

            return $this->parseRenderMethod($method);
        }

        return [[], []];
    }

    /**
     * Parse a render() method to extract the JSON response structure.
     *
     * @return array{0: array<string, SchemaObject>, 1: string[]}
     */
    private function parseRenderMethod(Stmt\ClassMethod $method): array
    {
        $finder = new NodeFinder;
        $returns = $finder->findInstanceOf($method->stmts ?? [], Stmt\Return_::class);

        foreach ($returns as $return) {
            if ($return->expr === null) {
                continue;
            }

            // Walk up method chains: response()->json(...)->setStatusCode(...)
            $jsonCall = $this->findJsonCall($return->expr);
            if ($jsonCall === null) {
                continue;
            }

            $firstArg = $jsonCall->args[0] ?? null;
            if ($firstArg === null) {
                continue;
            }

            $argValue = $firstArg->value;

            // Case 1: array_merge([base], $error, $trace)
            if ($argValue instanceof Expr\FuncCall
                && $argValue->name instanceof Node\Name
                && $argValue->name->toString() === 'array_merge') {
                return $this->parseArrayMergeArgs($argValue->args, $method);
            }

            // Case 2: Direct array literal
            if ($argValue instanceof Expr\Array_) {
                return $this->extractPropertiesFromArray($argValue, $method);
            }
        }

        return [[], []];
    }

    /**
     * Find a ->json(...) method call in a potentially chained expression.
     */
    private function findJsonCall(Expr $expr): ?Expr\MethodCall
    {
        // Direct: response()->json(...)
        if ($expr instanceof Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'json') {
            return $expr;
        }

        // Chained: response()->json(...)->setStatusCode(...)
        if ($expr instanceof Expr\MethodCall && $expr->var instanceof Expr) {
            return $this->findJsonCall($expr->var);
        }

        return null;
    }

    /**
     * Parse array_merge([base], $conditional, $debugOnly) arguments.
     *
     * @param  Node\Arg[]  $args
     * @return array{0: array<string, SchemaObject>, 1: string[]}
     */
    private function parseArrayMergeArgs(array $args, Stmt\ClassMethod $method): array
    {
        $properties = [];
        $required = [];

        foreach ($args as $arg) {
            $value = $arg->value;

            // Direct array — these are the base properties
            if ($value instanceof Expr\Array_) {
                [$props, $reqs] = $this->extractPropertiesFromArray($value, $method);
                $properties = array_merge($properties, $props);
                $required = array_merge($required, $reqs);

                continue;
            }

            // Variable reference (e.g. $error, $trace) — skip debug-gated ones
            if ($value instanceof Expr\Variable && is_string($value->name)) {
                if ($this->isDebugGatedVariable($value->name, $method)) {
                    continue;
                }
                // Non-debug variables are conditional (like $error) — handled separately
            }
        }

        return [$properties, $required];
    }

    /**
     * Check if a variable is gated behind debug mode.
     */
    private function isDebugGatedVariable(string $varName, Stmt\ClassMethod $method): bool
    {
        $finder = new NodeFinder;
        $assigns = $finder->findInstanceOf($method->stmts ?? [], Expr\Assign::class);

        foreach ($assigns as $assign) {
            if (! $assign->var instanceof Expr\Variable || $assign->var->name !== $varName) {
                continue;
            }

            // Check if the assignment is a ternary with debug check
            if ($assign->expr instanceof Expr\Ternary) {
                $cond = $assign->expr->cond;
                if ($this->isDebugCheck($cond)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a condition is a debug mode check.
     */
    private function isDebugCheck(Node $node): bool
    {
        // app()->hasDebugModeEnabled()
        if ($node instanceof Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'hasDebugModeEnabled') {
            return true;
        }

        // config('app.debug')
        if ($node instanceof Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toString() === 'config') {
            $firstArg = $node->args[0] ?? null;
            if ($firstArg !== null
                && $firstArg->value instanceof Node\Scalar\String_
                && $firstArg->value->value === 'app.debug') {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract property schemas from an array literal.
     *
     * @return array{0: array<string, SchemaObject>, 1: string[]}
     */
    private function extractPropertiesFromArray(Expr\Array_ $array, Stmt\ClassMethod $method): array
    {
        $properties = [];
        $required = [];

        foreach ($array->items as $item) {
            if (! $item instanceof ArrayItem || $item->key === null) {
                continue;
            }

            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = $item->key->value;
            $schema = $this->inferSchemaFromValue($item->value);

            if ($schema !== null) {
                $properties[$key] = $schema;
                $required[] = $key;
            }
        }

        return [$properties, $required];
    }

    /**
     * Infer a SchemaObject from an AST value expression.
     */
    private function inferSchemaFromValue(Node $node): SchemaObject
    {
        // String literals
        if ($node instanceof Node\Scalar\String_) {
            return new SchemaObject(type: 'string', example: $node->value);
        }

        // Integer literals
        if ($node instanceof Node\Scalar\Int_) {
            return SchemaObject::integer();
        }

        // Method calls — infer type from known patterns
        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $methodName = $node->name->toString();

            return match (true) {
                // Carbon::now()->format(...) or (new Carbon())->...->format(...)
                $methodName === 'format' => new SchemaObject(type: 'string', format: 'date-time'),
                // $request->getPathInfo(), $request->path()
                in_array($methodName, ['getPathInfo', 'path']) => new SchemaObject(type: 'string'),
                // $e->getCode(), $e->getStatus()
                in_array($methodName, ['getCode', 'getStatus', 'getStatusCode']) => SchemaObject::integer(),
                // $e->getMessage(), $this->getMessage(...)
                in_array($methodName, ['getMessage', 'getErrorMessage']) => new SchemaObject(type: 'string'),
                // $request->header(...)
                $methodName === 'header' => new SchemaObject(type: 'string'),
                default => new SchemaObject(type: 'string'),
            };
        }

        // Static calls — Carbon::now(), etc.
        if ($node instanceof Expr\StaticCall) {
            return new SchemaObject(type: 'string', format: 'date-time');
        }

        // new Carbon() / new SomeClass()
        if ($node instanceof Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $className = $node->class->toString();
                if (str_contains($className, 'Carbon') || str_contains($className, 'DateTime')) {
                    return new SchemaObject(type: 'string', format: 'date-time');
                }
            }

            return new SchemaObject(type: 'string');
        }

        // Function calls: $this->methodName(...), $this->getErrorMessage(...)
        if ($node instanceof Expr\FuncCall) {
            return new SchemaObject(type: 'string');
        }

        // Variable or property fetch — generic string
        if ($node instanceof Expr\Variable || $node instanceof Expr\PropertyFetch) {
            return new SchemaObject(type: 'string');
        }

        return new SchemaObject(type: 'string');
    }

    /**
     * Extract conditional properties from private helper methods.
     * Looks for methods that return arrays with keys not present in the base render array.
     *
     * @param  array<string, SchemaObject>  $baseProperties
     * @return array<string, SchemaObject>
     */
    private function extractConditionalProperties(Stmt\Class_ $classNode, array $baseProperties): array
    {
        $conditional = [];
        $finder = new NodeFinder;
        $methods = $finder->findInstanceOf($classNode->stmts, Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            $name = $method->name->toString();

            // Skip render() itself and common non-structural methods
            if (in_array($name, ['render', 'register', 'report', 'matchStatusCode', '__construct'])) {
                continue;
            }

            // Look for return statements with arrays containing 'errors' or 'details'
            $returns = $finder->findInstanceOf($method->stmts ?? [], Stmt\Return_::class);

            foreach ($returns as $return) {
                if ($return->expr === null) {
                    continue;
                }

                $arrays = $this->findArraysInExpr($return->expr);

                foreach ($arrays as $array) {
                    foreach ($array->items as $item) {
                        if (! $item instanceof ArrayItem || $item->key === null) {
                            continue;
                        }

                        if (! $item->key instanceof Node\Scalar\String_) {
                            continue;
                        }

                        $key = $item->key->value;

                        // Only capture keys not already in base properties
                        if (isset($baseProperties[$key]) || isset($conditional[$key])) {
                            continue;
                        }

                        if (in_array($key, ['errors', 'details'])) {
                            $conditional[$key] = $this->buildValidationErrorsSchema();
                        }
                    }
                }
            }
        }

        return $conditional;
    }

    /**
     * Recursively find Array_ nodes in an expression.
     *
     * @return Expr\Array_[]
     */
    private function findArraysInExpr(Expr $expr): array
    {
        if ($expr instanceof Expr\Array_) {
            return [$expr];
        }

        $arrays = [];

        if ($expr instanceof Expr\Ternary) {
            if ($expr->if !== null) {
                $arrays = array_merge($arrays, $this->findArraysInExpr($expr->if));
            }
            $arrays = array_merge($arrays, $this->findArraysInExpr($expr->else));
        }

        return $arrays;
    }

    /**
     * Build the standard validation errors schema: object with additionalProperties of array of {message, i18n?}.
     */
    private function buildValidationErrorsSchema(): SchemaObject
    {
        $errorItem = SchemaObject::object(
            properties: [
                'message' => new SchemaObject(type: 'string'),
                'i18n' => new SchemaObject(type: 'string'),
            ],
        );

        return new SchemaObject(
            type: 'object',
            extra: [
                'additionalProperties' => [
                    'type' => 'array',
                    'items' => $errorItem->jsonSerialize(),
                ],
            ],
        );
    }

    /**
     * Extract status-code-specific messages from getMessage()/getErrorMessage() methods.
     *
     * @return array<int, string>
     */
    private function extractStatusMessages(Stmt\Class_ $classNode): array
    {
        $messages = [];
        $finder = new NodeFinder;
        $methods = $finder->findInstanceOf($classNode->stmts, Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            $name = $method->name->toString();
            if (! in_array($name, ['getMessage', 'getErrorMessage', 'getErrorMessageForStatus'])) {
                continue;
            }

            // Look for match() expressions
            $matchExprs = $finder->findInstanceOf($method->stmts ?? [], Expr\Match_::class);

            foreach ($matchExprs as $match) {
                foreach ($match->arms as $arm) {
                    if ($arm->conds === null) {
                        continue; // default arm
                    }

                    $message = null;
                    if ($arm->body instanceof Node\Scalar\String_) {
                        $message = $arm->body->value;
                    }

                    if ($message === null) {
                        continue;
                    }

                    foreach ($arm->conds as $cond) {
                        $statusCode = $this->resolveMatchConditionToStatusCode($cond);
                        if ($statusCode !== null) {
                            $messages[$statusCode] = $message;
                        }
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Resolve a match arm condition to a status code integer.
     */
    private function resolveMatchConditionToStatusCode(Node $cond): ?int
    {
        if ($cond instanceof Node\Scalar\Int_) {
            return $cond->value;
        }

        // Response::HTTP_UNAUTHORIZED etc.
        if ($cond instanceof Expr\ClassConstFetch
            && $cond->name instanceof Node\Identifier) {
            $constName = $cond->name->toString();
            if (str_starts_with($constName, 'HTTP_')) {
                $refClass = new \ReflectionClass(Response::class);
                $constants = $refClass->getConstants();

                return isset($constants[$constName]) && is_int($constants[$constName])
                    ? $constants[$constName]
                    : null;
            }
        }

        return null;
    }
}
