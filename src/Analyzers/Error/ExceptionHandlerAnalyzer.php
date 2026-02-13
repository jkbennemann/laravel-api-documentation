<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Error;

use JkBennemann\LaravelApiDocumentation\Contracts\ExceptionSchemaProvider;
use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Schema\PhpDocParser;
use PhpParser\Node;
use PhpParser\NodeFinder;

class ExceptionHandlerAnalyzer implements ResponseExtractor
{
    /** @var ExceptionSchemaProvider[] */
    private array $providers;

    private ?PhpDocParser $phpDocParser;

    /**
     * @param  ExceptionSchemaProvider[]  $providers
     */
    public function __construct(
        array $providers = [],
        private ?ExceptionHandlerSchemaAnalyzer $handlerAnalyzer = null,
        ?PhpDocParser $phpDocParser = null,
    ) {
        $this->providers = $providers;
        $this->phpDocParser = $phpDocParser;
    }

    public function extract(AnalysisContext $ctx): array
    {
        if (! $ctx->hasAst()) {
            return [];
        }

        $results = [];
        $rawThrowClasses = $this->findThrownExceptions($ctx->astNode);

        // Resolve short names to FQCN
        $throwClasses = [];
        foreach ($rawThrowClasses as $name) {
            $resolved = $this->resolveExceptionClass($name, $ctx);
            if ($resolved !== null) {
                $throwClasses[] = $resolved;
            }
        }

        // Also detect @throws from PHPDoc
        if ($this->phpDocParser !== null && $ctx->hasReflection()) {
            $phpDocThrows = $this->phpDocParser->getThrows($ctx->reflectionMethod);
            foreach ($phpDocThrows as $exceptionName) {
                // Resolve short name to FQCN
                $resolved = $this->resolveExceptionClass($exceptionName, $ctx);
                if ($resolved !== null && ! in_array($resolved, $throwClasses, true)) {
                    $throwClasses[] = $resolved;
                }
            }
        }

        foreach ($throwClasses as $exceptionClass) {
            // Check registered exception providers first
            foreach ($this->providers as $provider) {
                if ($provider->provides($exceptionClass)) {
                    $results[] = $provider->getResponse($exceptionClass);

                    continue 2;
                }
            }

            // Fall back to reflection-based analysis
            $result = $this->analyzeException($exceptionClass);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        // Also detect abort() calls
        $abortResults = $this->findAbortCalls($ctx->astNode);
        foreach ($abortResults as $result) {
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Find exception classes thrown in the method body.
     *
     * @return string[]
     */
    private function findThrownExceptions(Node $node): array
    {
        $finder = new NodeFinder;
        $classes = [];

        // PhpParser v5: throw is an expression (Expr\Throw_)
        $throws = $finder->findInstanceOf($node, Node\Expr\Throw_::class);
        foreach ($throws as $throw) {
            if ($throw->expr instanceof Node\Expr\New_
                && $throw->expr->class instanceof Node\Name) {
                $classes[] = $throw->expr->class->toString();
            }
        }

        return array_unique($classes);
    }

    /**
     * Find abort() and abort_if()/abort_unless() calls.
     *
     * @return ResponseResult[]
     */
    private function findAbortCalls(Node $node): array
    {
        $finder = new NodeFinder;
        $calls = $finder->findInstanceOf($node, Node\Expr\FuncCall::class);

        $results = [];
        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Name) {
                continue;
            }

            $funcName = $call->name->toString();
            if (! in_array($funcName, ['abort', 'abort_if', 'abort_unless'], true)) {
                continue;
            }

            // abort(404), abort(403, 'message'), abort_if(cond, 404)
            $statusArg = $funcName === 'abort'
                ? ($call->args[0] ?? null)
                : ($call->args[1] ?? null);

            if ($statusArg !== null
                && $statusArg->value instanceof Node\Scalar\Int_) {
                $statusCode = $statusArg->value->value;

                $messageArg = $funcName === 'abort'
                    ? ($call->args[1] ?? null)
                    : ($call->args[2] ?? null);

                $message = $this->descriptionForStatus($statusCode);
                if ($messageArg !== null && $messageArg->value instanceof Node\Scalar\String_) {
                    $message = $messageArg->value->value;
                }

                $schema = $this->handlerAnalyzer?->getErrorSchema($statusCode)
                    ?? SchemaObject::object(
                        properties: ['message' => new SchemaObject(type: 'string', example: $message)],
                        required: ['message'],
                    );

                $results[] = new ResponseResult(
                    statusCode: $statusCode,
                    schema: $schema,
                    description: $message,
                    source: 'analyzer:exception-handler',
                );
            }
        }

        return $results;
    }

    private function analyzeException(string $className): ?ResponseResult
    {
        if (! class_exists($className)) {
            return null;
        }

        $reflection = new \ReflectionClass($className);

        // Try render() method first — custom exceptions often define their own response
        $renderResult = $this->analyzeRenderMethod($reflection);
        if ($renderResult !== null) {
            return $renderResult;
        }

        // Determine status code from known exception types
        $statusCode = $this->resolveStatusCode($reflection);
        if ($statusCode === null) {
            return null;
        }

        $message = $this->descriptionForStatus($statusCode);

        $schema = $this->handlerAnalyzer?->getErrorSchema($statusCode)
            ?? SchemaObject::object(
                properties: ['message' => new SchemaObject(type: 'string', example: $message)],
                required: ['message'],
            );

        return new ResponseResult(
            statusCode: $statusCode,
            schema: $schema,
            description: $message,
            source: 'analyzer:exception-handler',
        );
    }

    /**
     * Analyze a custom exception's render() method to extract status code and response schema.
     */
    private function analyzeRenderMethod(\ReflectionClass $reflection): ?ResponseResult
    {
        if (! $reflection->hasMethod('render')) {
            return null;
        }

        try {
            $method = $reflection->getMethod('render');

            // Only analyze render() defined on the exception itself, not inherited
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
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

            $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
            $stmts = $parser->parse($code);
            if ($stmts === null) {
                return null;
            }

            $finder = new NodeFinder;
            $methods = $finder->findInstanceOf($stmts, \PhpParser\Node\Stmt\ClassMethod::class);

            foreach ($methods as $classMethod) {
                if ($classMethod->name->toString() !== 'render') {
                    continue;
                }

                return $this->extractResponseFromRenderBody($classMethod);
            }
        } catch (\Throwable) {
            // Parsing failed
        }

        return null;
    }

    /**
     * Extract status code and schema from a render() method's AST.
     */
    private function extractResponseFromRenderBody(\PhpParser\Node\Stmt\ClassMethod $method): ?ResponseResult
    {
        $finder = new NodeFinder;
        $returns = $finder->findInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class);

        foreach ($returns as $return) {
            if ($return->expr === null) {
                continue;
            }

            $expr = $return->expr;

            // response()->json([...], status)
            if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
                $methodName = $expr->name->toString();

                if ($methodName === 'json') {
                    $statusCode = $this->extractIntArg($expr->args, 1) ?? 500;
                    $schema = $this->extractSchemaFromArrayArg($expr->args, 0);

                    return new ResponseResult(
                        statusCode: $statusCode,
                        schema: $schema,
                        description: $this->descriptionForStatus($statusCode),
                        source: 'analyzer:exception-render',
                    );
                }
            }

            // new JsonResponse([...], status)
            if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
                $className = $expr->class->toString();
                if (str_contains($className, 'JsonResponse')) {
                    $statusCode = $this->extractIntArg($expr->args, 1) ?? 500;
                    $schema = $this->extractSchemaFromArrayArg($expr->args, 0);

                    return new ResponseResult(
                        statusCode: $statusCode,
                        schema: $schema,
                        description: $this->descriptionForStatus($statusCode),
                        source: 'analyzer:exception-render',
                    );
                }
            }
        }

        return null;
    }

    private function extractIntArg(array $args, int $index): ?int
    {
        if (! isset($args[$index])) {
            return null;
        }

        $value = $args[$index]->value ?? null;
        if ($value instanceof Node\Scalar\Int_) {
            return $value->value;
        }

        return null;
    }

    /**
     * Extract a simple schema from an inline array argument like ['error' => 'message'].
     */
    private function extractSchemaFromArrayArg(array $args, int $index): SchemaObject
    {
        if (! isset($args[$index])) {
            return SchemaObject::object(
                properties: ['message' => new SchemaObject(type: 'string')],
                required: ['message'],
            );
        }

        $value = $args[$index]->value ?? null;
        if (! $value instanceof Node\Expr\Array_) {
            return SchemaObject::object(
                properties: ['message' => new SchemaObject(type: 'string')],
                required: ['message'],
            );
        }

        $properties = [];
        $required = [];

        foreach ($value->items as $item) {
            if (! $item instanceof Node\ArrayItem || $item->key === null) {
                continue;
            }

            $key = null;
            if ($item->key instanceof Node\Scalar\String_) {
                $key = $item->key->value;
            }

            if ($key === null) {
                continue;
            }

            // Infer simple types from value
            $propSchema = match (true) {
                $item->value instanceof Node\Scalar\String_ => new SchemaObject(type: 'string', example: $item->value->value),
                $item->value instanceof Node\Scalar\Int_ => SchemaObject::integer(),
                $item->value instanceof Node\Expr\ConstFetch => SchemaObject::boolean(),
                default => new SchemaObject(type: 'string'),
            };

            $properties[$key] = $propSchema;
            $required[] = $key;
        }

        if (empty($properties)) {
            return SchemaObject::object(
                properties: ['message' => new SchemaObject(type: 'string')],
                required: ['message'],
            );
        }

        return SchemaObject::object(properties: $properties, required: $required);
    }

    private function resolveStatusCode(\ReflectionClass $reflection): ?int
    {
        $name = $reflection->getName();

        // Check handler's custom mapping first
        $handlerCode = $this->handlerAnalyzer?->getStatusCodeForException($name);
        if ($handlerCode !== null) {
            return $handlerCode;
        }

        // Symfony HTTP exceptions
        $httpExceptionMap = [
            'Symfony\Component\HttpKernel\Exception\NotFoundHttpException' => 404,
            'Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException' => 403,
            'Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException' => 401,
            'Symfony\Component\HttpKernel\Exception\BadRequestHttpException' => 400,
            'Symfony\Component\HttpKernel\Exception\ConflictHttpException' => 409,
            'Symfony\Component\HttpKernel\Exception\GoneHttpException' => 410,
            'Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException' => 405,
            'Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException' => 429,
            'Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException' => 503,
            'Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException' => 422,
        ];

        if (isset($httpExceptionMap[$name])) {
            return $httpExceptionMap[$name];
        }

        // Laravel exceptions
        $laravelMap = [
            'Illuminate\Database\Eloquent\ModelNotFoundException' => 404,
            'Illuminate\Auth\AuthenticationException' => 401,
            'Illuminate\Auth\Access\AuthorizationException' => 403,
            'Illuminate\Validation\ValidationException' => 422,
        ];

        if (isset($laravelMap[$name])) {
            return $laravelMap[$name];
        }

        // Check if it extends HttpException — try to resolve status code from constructor default
        if ($reflection->isSubclassOf('Symfony\Component\HttpKernel\Exception\HttpException')) {
            try {
                $constructor = $reflection->getConstructor();
                if ($constructor !== null) {
                    foreach ($constructor->getParameters() as $param) {
                        if ($param->getName() === 'statusCode' && $param->isDefaultValueAvailable()) {
                            $code = $param->getDefaultValue();
                            if (is_int($code) && $code >= 100 && $code <= 599) {
                                return $code;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Can't reflect constructor - skip
            }
        }

        return null;
    }

    /**
     * Resolve a potentially short exception class name to its FQCN.
     */
    private function resolveExceptionClass(string $name, AnalysisContext $ctx): ?string
    {
        if (class_exists($name)) {
            return $name;
        }

        // Try resolving from the controller's use statements via source file
        if ($ctx->sourceFilePath !== null) {
            try {
                $code = file_get_contents($ctx->sourceFilePath);
                if ($code !== false) {
                    // Quick regex to find use statements
                    if (preg_match('/^use\s+([^\s;]+\\\\'.preg_quote($name, '/').')\s*;/m', $code, $matches)) {
                        if (class_exists($matches[1])) {
                            return $matches[1];
                        }
                    }
                }
            } catch (\Throwable) {
                // Skip
            }
        }

        // Try with controller namespace
        if ($ctx->controllerClass() !== null) {
            $namespace = substr($ctx->controllerClass(), 0, (int) strrpos($ctx->controllerClass(), '\\'));
            $fqcn = $namespace.'\\'.$name;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    private function descriptionForStatus(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            410 => 'Gone',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
