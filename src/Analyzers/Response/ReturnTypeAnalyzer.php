<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Response;

use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Cache\AstCache;
use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Schema\ClassSchemaResolver;
use JkBennemann\LaravelApiDocumentation\Schema\EloquentModelAnalyzer;
use JkBennemann\LaravelApiDocumentation\Schema\PhpDocParser;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

class ReturnTypeAnalyzer implements ResponseExtractor
{
    private JsonResourceAnalyzer $resourceAnalyzer;

    private SpatieDataResponseAnalyzer $spatieAnalyzer;

    private ClassSchemaResolver $classResolver;

    private PhpDocParser $phpDocParser;

    /** @var array<string, array<string, string>> Use-statement cache keyed by file path */
    private array $useCache = [];

    private AstCache $astCache;

    public function __construct(
        SchemaRegistry $registry,
        ClassSchemaResolver $classResolver,
        EloquentModelAnalyzer $modelAnalyzer,
        PhpDocParser $phpDocParser,
        array $config = [],
        ?AstCache $astCache = null,
    ) {
        $this->classResolver = $classResolver;
        $this->phpDocParser = $phpDocParser;
        $this->astCache = $astCache ?? new AstCache(sys_get_temp_dir(), 0);
        $this->resourceAnalyzer = new JsonResourceAnalyzer($registry, $config, $astCache);
        $this->resourceAnalyzer->setClassResolver($classResolver);
        $this->resourceAnalyzer->setModelAnalyzer($modelAnalyzer);
        $this->spatieAnalyzer = new SpatieDataResponseAnalyzer($registry);
        $this->spatieAnalyzer->setClassResolver($classResolver);
    }

    public function extract(AnalysisContext $ctx): array
    {
        // When explicit #[DataResponse] attributes exist, the developer has declared
        // the response contract — don't add auto-detected responses that would conflict.
        if ($ctx->hasAttribute(DataResponse::class)) {
            return [];
        }

        $results = [];

        // Analyze return type declaration
        if ($ctx->reflectionMethod !== null) {
            $returnType = $ctx->reflectionMethod->getReturnType();
            if ($returnType instanceof \ReflectionNamedType) {
                $typeResults = $this->analyzeReturnTypeName($returnType->getName(), $ctx);
                $results = array_merge($results, $typeResults);
            }
        }

        // Analyze AST return statements for more detail
        if ($ctx->hasAst() && empty($results)) {
            $results = $this->analyzeReturnStatements($ctx);
        }

        // Try PHPDoc @return if nothing found yet
        if (empty($results) && $ctx->reflectionMethod !== null) {
            $results = $this->analyzePhpDocReturn($ctx);
        }

        // Default: 200 response with empty object if nothing found
        if (empty($results)) {
            $results[] = new ResponseResult(
                statusCode: 200,
                schema: SchemaObject::object(),
                description: 'Success',
                source: 'default',
            );
        }

        return $results;
    }

    /**
     * Try to extract response schema from PHPDoc @return annotation.
     *
     * @return ResponseResult[]
     */
    private function analyzePhpDocReturn(AnalysisContext $ctx): array
    {
        if ($ctx->reflectionMethod === null) {
            return [];
        }

        // Check if @return specifies a concrete resource/data class
        $returnClassName = $this->phpDocParser->getReturnClassName($ctx->reflectionMethod);
        if ($returnClassName !== null) {
            // Resolve the class name
            $resolved = $this->resolveClassName($returnClassName, $ctx);
            if ($resolved !== null) {
                // JsonResource subclass
                if (is_subclass_of($resolved, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                    $schema = $this->resourceAnalyzer->analyze($resolved);
                    if ($schema !== null) {
                        $isCollection = is_subclass_of($resolved, \Illuminate\Http\Resources\Json\ResourceCollection::class);
                        $schema = $this->applyResourceWrap($resolved, $schema);

                        return [new ResponseResult(
                            statusCode: 200,
                            schema: $schema,
                            description: 'Success',
                            source: 'phpdoc:return_resource',
                            isCollection: $isCollection,
                        )];
                    }
                }

                // Spatie Data
                if (class_exists(\Spatie\LaravelData\Data::class) && is_subclass_of($resolved, \Spatie\LaravelData\Data::class)) {
                    $schema = $this->spatieAnalyzer->analyze($resolved);
                    if ($schema !== null) {
                        return [new ResponseResult(
                            statusCode: 200,
                            schema: $schema,
                            description: 'Success',
                            source: 'phpdoc:return_spatie_data',
                        )];
                    }
                }
            }
        }

        // Fall back to general @return type mapping
        $schema = $this->phpDocParser->getReturnType($ctx->reflectionMethod);
        if ($schema !== null && $schema->type !== null) {
            return [new ResponseResult(
                statusCode: 200,
                schema: $schema,
                description: 'Success',
                source: 'phpdoc:return_type',
            )];
        }

        return [];
    }

    /**
     * @return ResponseResult[]
     */
    private function analyzeReturnTypeName(string $typeName, AnalysisContext $ctx): array
    {
        $typeName = ltrim($typeName, '\\');

        // JsonResponse
        if ($typeName === 'Illuminate\Http\JsonResponse') {
            return $this->analyzeReturnStatements($ctx);
        }

        // Base JsonResource or AnonymousResourceCollection — fall through to AST analysis
        // to detect the actual resource class from `return SomeResource::collection(...)` or `return new SomeResource()`
        if ($typeName === 'Illuminate\Http\Resources\Json\JsonResource'
            || $typeName === 'Illuminate\Http\Resources\Json\AnonymousResourceCollection') {
            return $this->analyzeReturnStatements($ctx);
        }

        // JsonResource subclass
        if (is_subclass_of($typeName, \Illuminate\Http\Resources\Json\JsonResource::class)) {
            $schema = $this->resourceAnalyzer->analyze($typeName);
            if ($schema !== null) {
                // When analyzeCollection returns a generic array(object()) for AnonymousResourceCollection
                // subclasses (no $collects), fall through to AST analysis for better results
                if (is_subclass_of($typeName, \Illuminate\Http\Resources\Json\ResourceCollection::class)
                    && $schema->type === 'array'
                    && $schema->items !== null
                    && $schema->items->type === 'object'
                    && empty($schema->items->properties)
                ) {
                    $astResults = $this->analyzeReturnStatements($ctx);
                    if (! empty($astResults)) {
                        return $astResults;
                    }
                }

                $isCollection = is_subclass_of($typeName, \Illuminate\Http\Resources\Json\ResourceCollection::class);
                $schema = $this->applyResourceWrap($typeName, $schema);

                return [new ResponseResult(
                    statusCode: 200,
                    schema: $schema,
                    description: 'Success',
                    source: 'return_type:JsonResource',
                    isCollection: $isCollection,
                )];
            }
        }

        // Spatie Data
        if (class_exists(\Spatie\LaravelData\Data::class) && is_subclass_of($typeName, \Spatie\LaravelData\Data::class)) {
            $schema = $this->spatieAnalyzer->analyze($typeName);
            if ($schema !== null) {
                return [new ResponseResult(
                    statusCode: 200,
                    schema: $schema,
                    description: 'Success',
                    source: 'return_type:SpatieData',
                )];
            }
        }

        // Response (no content)
        if (in_array($typeName, ['Illuminate\Http\Response', 'Symfony\Component\HttpFoundation\Response'])) {
            return $this->analyzeReturnStatements($ctx);
        }

        // Void
        if ($typeName === 'void') {
            return [ResponseResult::noContent()];
        }

        return [];
    }

    /**
     * @return ResponseResult[]
     */
    private function analyzeReturnStatements(AnalysisContext $ctx): array
    {
        if (! $ctx->hasAst()) {
            return [];
        }

        $results = [];
        $nodeFinder = new NodeFinder;
        $returns = $nodeFinder->findInstanceOf($ctx->astNode->stmts ?? [], Return_::class);

        foreach ($returns as $return) {
            if ($return->expr === null) {
                continue;
            }

            $result = $this->analyzeReturnExpression($return->expr, $ctx);
            if ($result !== null) {
                $results[$result->statusCode] = $result;
            }
        }

        // Also detect abort() and throw calls for error responses
        $results = array_merge($results, $this->detectAbortCalls($ctx));

        return array_values($results);
    }

    private function analyzeReturnExpression(Expr $expr, AnalysisContext $ctx): ?ResponseResult
    {
        // response()->json(...)
        if ($expr instanceof MethodCall) {
            return $this->analyzeMethodCallReturn($expr, $ctx);
        }

        // new JsonResponse(...)
        if ($expr instanceof New_) {
            return $this->analyzeNewReturn($expr, $ctx);
        }

        // Response::json(...)
        if ($expr instanceof StaticCall) {
            return $this->analyzeStaticCallReturn($expr, $ctx);
        }

        // response($data, $status) — bare function call
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Name) {
            $funcName = $expr->name->toString();
            if ($funcName === 'response') {
                $statusCode = $this->extractStatusCodeArg($expr->args, 1) ?? 200;
                if ($statusCode === 204) {
                    return ResponseResult::noContent();
                }

                // Try to resolve schema from the first argument
                $schema = null;
                if (isset($expr->args[0])) {
                    $schema = $this->resolveArgumentSchema($expr->args[0]->value, $ctx);
                }

                return new ResponseResult(
                    statusCode: $statusCode,
                    schema: $schema ?? SchemaObject::object(),
                    description: $this->descriptionForStatus($statusCode),
                    source: 'ast:response_helper',
                );
            }
        }

        return null;
    }

    private function analyzeMethodCallReturn(MethodCall $call, AnalysisContext $ctx): ?ResponseResult
    {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }

        $methodName = $call->name->toString();

        // Unwrap method chains: Resource::make()->response()->setStatusCode(201)
        // Check if the outermost method is a passthrough/status-setter
        if (in_array($methodName, ['setStatusCode', 'response', 'additional', 'withHeaders', 'header', 'withResponse'], true)) {
            [$rootExpr, $chainStatusCode, $chainHeaders] = $this->unwrapMethodChain($call);

            if ($rootExpr instanceof StaticCall) {
                $result = $this->analyzeStaticCallReturn($rootExpr, $ctx);
                if ($result !== null && ($chainStatusCode !== 200 || ! empty($chainHeaders))) {
                    return new ResponseResult(
                        statusCode: $chainStatusCode,
                        schema: $result->schema,
                        description: $this->descriptionForStatus($chainStatusCode),
                        source: $result->source,
                        headers: array_merge($result->headers, $chainHeaders),
                        isCollection: $result->isCollection,
                    );
                }

                return $result;
            }

            if ($rootExpr instanceof New_) {
                $result = $this->analyzeNewReturn($rootExpr, $ctx);
                if ($result !== null && ($chainStatusCode !== 200 || ! empty($chainHeaders))) {
                    return new ResponseResult(
                        statusCode: $chainStatusCode,
                        schema: $result->schema,
                        description: $this->descriptionForStatus($chainStatusCode),
                        source: $result->source,
                        headers: array_merge($result->headers, $chainHeaders),
                        isCollection: $result->isCollection,
                    );
                }

                return $result;
            }
        }

        // ->json($data, $status)
        if ($methodName === 'json') {
            $statusCode = $this->extractStatusCodeArg($call->args, 1) ?? 200;

            if ($statusCode === 204) {
                return ResponseResult::noContent();
            }

            $schema = $this->extractInlineArraySchema($call->args, 0, $ctx);

            return new ResponseResult(
                statusCode: $statusCode,
                schema: $schema,
                description: $this->descriptionForStatus($statusCode),
                source: 'ast:response_json',
            );
        }

        // ->noContent()
        if ($methodName === 'noContent') {
            $statusCode = $this->extractStatusCodeArg($call->args, 0) ?? 204;

            return ResponseResult::noContent($statusCode);
        }

        // ->created()
        if ($methodName === 'created') {
            $schema = $this->extractInlineArraySchema($call->args, 0, $ctx);

            return new ResponseResult(
                statusCode: 201,
                schema: $schema,
                description: 'Created',
                source: 'ast:response_created',
            );
        }

        // ->make() on a resource
        if ($methodName === 'make' && $call->var instanceof StaticCall) {
            // Resource::make()
        }

        // $this->someMethod() — check return type via reflection for resource indirection
        if ($call->var instanceof Expr\Variable
            && $call->var->name === 'this'
            && $ctx->controllerClass() !== null
        ) {
            try {
                $refClass = new \ReflectionClass($ctx->controllerClass());
                if ($refClass->hasMethod($methodName)) {
                    $refMethod = $refClass->getMethod($methodName);
                    $returnType = $refMethod->getReturnType();
                    if ($returnType instanceof \ReflectionNamedType) {
                        $returnTypeName = ltrim($returnType->getName(), '\\');
                        if (is_subclass_of($returnTypeName, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                            $schema = $this->resourceAnalyzer->analyze($returnTypeName);
                            if ($schema !== null) {
                                // When the result is a generic array(object()), the return type is too
                                // abstract (e.g. AnonymousResourceCollection). Fall through to body analysis.
                                $isGeneric = $schema->type === 'array'
                                    && $schema->items !== null
                                    && $schema->items->type === 'object'
                                    && empty($schema->items->properties);

                                if (! $isGeneric) {
                                    $isCollection = is_subclass_of($returnTypeName, \Illuminate\Http\Resources\Json\ResourceCollection::class);
                                    $schema = $this->applyResourceWrap($returnTypeName, $schema);

                                    return new ResponseResult(
                                        statusCode: 200,
                                        schema: $schema,
                                        description: 'Success',
                                        source: 'ast:method_indirection',
                                        isCollection: $isCollection,
                                    );
                                }
                            }
                        }
                    }

                    // Return type didn't yield a rich result — trace into the method body
                    $bodyResult = $this->analyzeHelperMethodBody($refClass, $methodName, $ctx);
                    if ($bodyResult !== null) {
                        return $bodyResult;
                    }
                }
            } catch (\Throwable) {
                // Reflection failed — skip
            }
        }

        return null;
    }

    private function analyzeNewReturn(New_ $newExpr, AnalysisContext $ctx): ?ResponseResult
    {
        if (! $newExpr->class instanceof Name) {
            return null;
        }

        $className = $newExpr->class->toString();

        // Resolve fully qualified name if possible
        if ($ctx->hasReflection()) {
            $resolvedName = $this->resolveClassName($className, $ctx);
            if ($resolvedName !== null) {
                $className = $resolvedName;
            }
        }

        // new JsonResponse(...)
        if (str_contains($className, 'JsonResponse')) {
            $statusCode = $this->extractStatusCodeArg($newExpr->args, 1) ?? 200;
            $schema = $this->extractInlineArraySchema($newExpr->args, 0, $ctx);

            return new ResponseResult(
                statusCode: $statusCode,
                schema: $schema,
                description: $this->descriptionForStatus($statusCode),
                source: 'ast:new_json_response',
            );
        }

        // new SomeResource($model) — try resolving as a JsonResource subclass
        $resolved = $this->resolveClassName($className, $ctx);
        if ($resolved !== null && is_subclass_of($resolved, \Illuminate\Http\Resources\Json\JsonResource::class)) {
            $schema = $this->resourceAnalyzer->analyze($resolved);
            if ($schema !== null) {
                $isCollection = is_subclass_of($resolved, \Illuminate\Http\Resources\Json\ResourceCollection::class);
                $schema = $this->applyResourceWrap($resolved, $schema);

                return new ResponseResult(
                    statusCode: 200,
                    schema: $schema,
                    description: 'Success',
                    source: 'ast:new_resource',
                    isCollection: $isCollection,
                );
            }
        }

        return null;
    }

    private function analyzeStaticCallReturn(StaticCall $call, AnalysisContext $ctx): ?ResponseResult
    {
        if (! $call->name instanceof Node\Identifier || ! $call->class instanceof Name) {
            return null;
        }

        $className = $call->class->toString();
        $methodName = $call->name->toString();

        // Response::json(...)
        if ($methodName === 'json') {
            $statusCode = $this->extractStatusCodeArg($call->args, 1) ?? 200;
            $schema = $this->extractInlineArraySchema($call->args, 0, $ctx);

            return new ResponseResult(
                statusCode: $statusCode,
                schema: $schema,
                description: $this->descriptionForStatus($statusCode),
                source: 'ast:static_json',
            );
        }

        // Resource::collection(...)
        if ($methodName === 'collection' || $methodName === 'make') {
            $resolvedName = $this->resolveClassName($className, $ctx);
            if ($resolvedName !== null && is_subclass_of($resolvedName, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                $schema = $this->resourceAnalyzer->analyze($resolvedName);
                if ($schema !== null) {
                    if ($methodName === 'collection') {
                        $schema = SchemaObject::array($schema);
                    }

                    $schema = $this->applyResourceWrap($resolvedName, $schema);

                    return new ResponseResult(
                        statusCode: 200,
                        schema: $schema,
                        description: 'Success',
                        source: 'ast:resource_static',
                        isCollection: $methodName === 'collection',
                    );
                }
            }
        }

        return null;
    }

    /**
     * @return ResponseResult[]
     */
    private function detectAbortCalls(AnalysisContext $ctx): array
    {
        $results = [];
        $nodeFinder = new NodeFinder;

        // Find abort() function calls
        $funcCalls = $nodeFinder->findInstanceOf($ctx->astNode->stmts ?? [], Node\Expr\FuncCall::class);

        foreach ($funcCalls as $call) {
            if (! $call->name instanceof Name) {
                continue;
            }

            $funcName = $call->name->toString();
            if (in_array($funcName, ['abort', 'abort_if', 'abort_unless'], true)) {
                $statusCode = $this->extractStatusCodeArg($call->args, $funcName === 'abort' ? 0 : 1);
                if ($statusCode !== null) {
                    $results[$statusCode] = new ResponseResult(
                        statusCode: $statusCode,
                        schema: $this->abortSchema($statusCode),
                        description: $this->descriptionForStatus($statusCode),
                        source: 'ast:abort',
                    );
                }
            }
        }

        return $results;
    }

    private function extractStatusCodeArg(array $args, int $index): ?int
    {
        if (! isset($args[$index])) {
            return null;
        }

        $value = $args[$index]->value ?? $args[$index];

        if ($value instanceof Node\Scalar\Int_) {
            return $value->value;
        }

        if ($value instanceof Node\Scalar\LNumber) {
            return $value->value;
        }

        // Handle class constants like Response::HTTP_NO_CONTENT
        if ($value instanceof Expr\ClassConstFetch && $value->name instanceof Node\Identifier) {
            return $this->resolveHttpStatusConstant($value->name->toString());
        }

        return null;
    }

    private function resolveHttpStatusConstant(string $constName): ?int
    {
        return match ($constName) {
            'HTTP_OK' => 200,
            'HTTP_CREATED' => 201,
            'HTTP_ACCEPTED' => 202,
            'HTTP_NO_CONTENT' => 204,
            'HTTP_MOVED_PERMANENTLY' => 301,
            'HTTP_FOUND' => 302,
            'HTTP_BAD_REQUEST' => 400,
            'HTTP_UNAUTHORIZED' => 401,
            'HTTP_FORBIDDEN' => 403,
            'HTTP_NOT_FOUND' => 404,
            'HTTP_CONFLICT' => 409,
            'HTTP_UNPROCESSABLE_ENTITY' => 422,
            'HTTP_TOO_MANY_REQUESTS' => 429,
            'HTTP_INTERNAL_SERVER_ERROR' => 500,
            'HTTP_SERVICE_UNAVAILABLE' => 503,
            default => null,
        };
    }

    private function resolveClassName(string $shortName, AnalysisContext $ctx): ?string
    {
        if (class_exists($shortName)) {
            return $shortName;
        }

        // Check use-statement map from the controller's source file
        if ($ctx->sourceFilePath !== null) {
            $useMap = $this->resolveUseStatements($ctx->sourceFilePath);
            if (isset($useMap[$shortName])) {
                $fqcn = $useMap[$shortName];
                if (class_exists($fqcn)) {
                    return $fqcn;
                }
            }
        }

        // Try with controller's namespace
        if ($ctx->controllerClass()) {
            $namespace = substr($ctx->controllerClass(), 0, strrpos($ctx->controllerClass(), '\\'));
            $fqcn = $namespace.'\\'.$shortName;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * Parse use statements from a PHP file and return a short-name → FQCN map.
     *
     * @return array<string, string>
     */
    private function resolveUseStatements(string $filePath): array
    {
        if (isset($this->useCache[$filePath])) {
            return $this->useCache[$filePath];
        }

        $map = [];

        try {
            $stmts = $this->astCache->parseFile($filePath);
            if ($stmts === null) {
                return $this->useCache[$filePath] = $map;
            }

            foreach ($stmts as $stmt) {
                if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                    foreach ($stmt->stmts as $nsStmt) {
                        if ($nsStmt instanceof \PhpParser\Node\Stmt\Use_) {
                            foreach ($nsStmt->uses as $use) {
                                $alias = $use->alias?->toString() ?? $use->name->getLast();
                                $map[$alias] = $use->name->toString();
                            }
                        }
                    }
                } elseif ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
                    foreach ($stmt->uses as $use) {
                        $alias = $use->alias?->toString() ?? $use->name->getLast();
                        $map[$alias] = $use->name->toString();
                    }
                }
            }
        } catch (\Throwable) {
            // Parsing failed — return empty map
        }

        return $this->useCache[$filePath] = $map;
    }

    /**
     * Wrap a resource schema with the resource's wrap key (e.g. {"data": ...}).
     * Only applies to top-level responses, not nested resources.
     */
    private function applyResourceWrap(string $resourceClass, SchemaObject $schema): SchemaObject
    {
        $wrapKey = JsonResourceAnalyzer::detectWrapKey($resourceClass);
        if ($wrapKey === null) {
            return $schema;
        }

        return SchemaObject::object(
            properties: [$wrapKey => $schema],
            required: [$wrapKey],
        );
    }

    /**
     * Parse the AST of a helper method on the controller (or its parent) and analyze return statements.
     * This handles patterns like $this->createResourceCollection() and $this->returnNoContent().
     */
    private function analyzeHelperMethodBody(\ReflectionClass $refClass, string $methodName, AnalysisContext $ctx): ?ResponseResult
    {
        try {
            $refMethod = $refClass->getMethod($methodName);
            $declaringClass = $refMethod->getDeclaringClass();
            $fileName = $declaringClass->getFileName();

            if (! $fileName || ! file_exists($fileName)) {
                return null;
            }

            $useMap = $this->resolveUseStatements($fileName);
            $namespace = $declaringClass->getNamespaceName();

            $stmts = $this->astCache->parseFile($fileName);
            if ($stmts === null) {
                return null;
            }

            $nodeFinder = new NodeFinder;
            $classMethods = $nodeFinder->findInstanceOf($stmts, \PhpParser\Node\Stmt\ClassMethod::class);

            $targetMethod = null;
            foreach ($classMethods as $method) {
                if ($method->name->toString() === $methodName) {
                    $targetMethod = $method;

                    break;
                }
            }

            if ($targetMethod === null) {
                return null;
            }

            $returns = $nodeFinder->findInstanceOf($targetMethod->stmts ?? [], Return_::class);

            foreach ($returns as $return) {
                if ($return->expr === null) {
                    continue;
                }

                $result = $this->analyzeHelperReturnExpression($return->expr, $useMap, $namespace, $ctx);
                if ($result !== null) {
                    return $result;
                }
            }
        } catch (\Throwable) {
            // Parsing/reflection failed
        }

        return null;
    }

    /**
     * Analyze a return expression from a helper method, using the helper's use-statement map for class resolution.
     */
    private function analyzeHelperReturnExpression(Expr $expr, array $useMap, string $namespace, AnalysisContext $ctx): ?ResponseResult
    {
        // SomeResource::collection($data) or SomeResource::make($data)
        if ($expr instanceof StaticCall && $expr->class instanceof Name && $expr->name instanceof Node\Identifier) {
            $className = $expr->class->toString();
            $callMethod = $expr->name->toString();
            $resolved = $this->resolveClassNameFromMap($className, $useMap, $namespace);

            if ($resolved !== null && is_subclass_of($resolved, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                if ($callMethod === 'collection') {
                    $schema = $this->resourceAnalyzer->analyze($resolved);
                    if ($schema !== null) {
                        $schema = SchemaObject::array($schema);
                        $schema = $this->applyResourceWrap($resolved, $schema);

                        return new ResponseResult(200, $schema, 'Success', source: 'ast:helper_collection', isCollection: true);
                    }
                } elseif ($callMethod === 'make') {
                    $schema = $this->resourceAnalyzer->analyze($resolved);
                    if ($schema !== null) {
                        $schema = $this->applyResourceWrap($resolved, $schema);

                        return new ResponseResult(200, $schema, 'Success', source: 'ast:helper_resource');
                    }
                }
            }
        }

        // new SomeResource($data)
        if ($expr instanceof New_ && $expr->class instanceof Name) {
            $className = $expr->class->toString();
            $resolved = $this->resolveClassNameFromMap($className, $useMap, $namespace);

            if ($resolved !== null && is_subclass_of($resolved, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                $schema = $this->resourceAnalyzer->analyze($resolved);
                if ($schema !== null) {
                    $schema = $this->applyResourceWrap($resolved, $schema);

                    return new ResponseResult(200, $schema, 'Success', source: 'ast:helper_resource');
                }
            }

            // new Response(status: HTTP_NO_CONTENT)
            if (str_contains($className, 'Response')) {
                foreach ($expr->args as $arg) {
                    if (! $arg instanceof Node\Arg) {
                        continue;
                    }
                    if ($arg->name?->toString() === 'status') {
                        if ($arg->value instanceof Node\Scalar\Int_) {
                            $code = $arg->value->value;
                            if ($code === 204) {
                                return ResponseResult::noContent();
                            }
                        }
                        if ($arg->value instanceof Expr\ClassConstFetch && $arg->value->name instanceof Node\Identifier) {
                            $code = $this->resolveHttpStatusConstant($arg->value->name->toString());
                            if ($code === 204) {
                                return ResponseResult::noContent();
                            }
                        }
                    }
                }
            }
        }

        // response()->json(null, 204) — MethodCall on response() function
        if ($expr instanceof MethodCall && $expr->name instanceof Node\Identifier) {
            $mName = $expr->name->toString();
            if ($mName === 'json') {
                $statusCode = $this->extractStatusCodeArg($expr->args, 1) ?? 200;
                if ($statusCode === 204) {
                    return ResponseResult::noContent();
                }
                $schema = $this->extractInlineArraySchemaFromHelper($expr->args, 0, $useMap, $namespace);

                return new ResponseResult(
                    statusCode: $statusCode,
                    schema: $schema,
                    description: $this->descriptionForStatus($statusCode),
                    source: 'ast:helper_json',
                );
            }
            if ($mName === 'noContent') {
                return ResponseResult::noContent();
            }
        }

        // response('', 204) — bare function call
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Name) {
            if ($expr->name->toString() === 'response') {
                $statusCode = $this->extractStatusCodeArg($expr->args, 1);
                if ($statusCode === 204) {
                    return ResponseResult::noContent();
                }
            }
        }

        return null;
    }

    private function resolveClassNameFromMap(string $shortName, array $useMap, string $namespace): ?string
    {
        if (class_exists($shortName)) {
            return $shortName;
        }

        if (isset($useMap[$shortName])) {
            $fqcn = $useMap[$shortName];
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        if ($namespace !== '') {
            $fqcn = $namespace.'\\'.$shortName;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * Recursively unwrap a method chain to find the root expression and any status code/header overrides.
     * Walks through passthrough methods like ->response(), ->setStatusCode(201), ->header(...), ->withHeaders([...]).
     *
     * @return array{Expr, int, array<string, array{description: string}>} [root expression, detected status code, headers]
     */
    private function unwrapMethodChain(MethodCall $call): array
    {
        $statusCode = 200;
        $headers = [];
        $current = $call;

        $passthroughMethods = ['response', 'additional', 'withHeaders', 'header', 'withResponse', 'setStatusCode'];

        while ($current instanceof MethodCall && $current->name instanceof Node\Identifier) {
            $name = $current->name->toString();

            if (! in_array($name, $passthroughMethods, true)) {
                break;
            }

            if ($name === 'setStatusCode' && isset($current->args[0])) {
                $code = $this->extractStatusCodeArg($current->args, 0);
                if ($code !== null) {
                    $statusCode = $code;
                }
            }

            // Extract headers from ->header('Name', 'value')
            if ($name === 'header' && isset($current->args[0])) {
                $headerName = $this->extractStringArg($current->args[0]);
                if ($headerName !== null) {
                    $headers[$headerName] = [
                        'description' => $headerName,
                        'schema' => ['type' => 'string'],
                    ];
                }
            }

            // Extract headers from ->withHeaders(['X-Custom' => 'value'])
            if ($name === 'withHeaders' && isset($current->args[0])) {
                $arrNode = $current->args[0]->value ?? null;
                if ($arrNode instanceof Node\Expr\Array_) {
                    foreach ($arrNode->items as $item) {
                        if ($item instanceof Node\ArrayItem && $item->key instanceof Node\Scalar\String_) {
                            $headers[$item->key->value] = [
                                'description' => $item->key->value,
                                'schema' => ['type' => 'string'],
                            ];
                        }
                    }
                }
            }

            $current = $current->var;
        }

        return [$current, $statusCode, $headers];
    }

    private function extractStringArg(Node\Arg $arg): ?string
    {
        $value = $arg->value;
        if ($value instanceof Node\Scalar\String_) {
            return $value->value;
        }

        return null;
    }

    /**
     * Try to extract a typed schema from an inline array argument.
     * Falls back to SchemaObject::object() if no array literal is found.
     */
    private function extractInlineArraySchema(array $args, int $index, AnalysisContext $ctx): SchemaObject
    {
        if (! isset($args[$index])) {
            return SchemaObject::object();
        }

        $dataNode = $args[$index]->value ?? $args[$index];

        // Direct array literal: response()->json(['key' => $value])
        if ($dataNode instanceof Node\Expr\Array_) {
            return $this->resourceAnalyzer->extractSchemaFromArray($dataNode, $ctx->controllerClass() ?? '');
        }

        // Variable: $data = [...]; return response()->json($data)
        if ($dataNode instanceof Expr\Variable && is_string($dataNode->name) && $ctx->hasAst()) {
            $traced = $this->traceVariableToArrayInMethod($dataNode->name, $ctx);
            if ($traced !== null) {
                return $this->resourceAnalyzer->extractSchemaFromArray($traced, $ctx->controllerClass() ?? '');
            }
        }

        return SchemaObject::object();
    }

    /**
     * Try to extract a typed schema from an inline array argument within a helper method context.
     */
    private function extractInlineArraySchemaFromHelper(array $args, int $index, array $useMap, string $namespace): SchemaObject
    {
        if (! isset($args[$index])) {
            return SchemaObject::object();
        }

        $dataNode = $args[$index]->value ?? $args[$index];

        if ($dataNode instanceof Node\Expr\Array_) {
            $contextClass = $namespace !== '' ? $namespace.'\\Unknown' : '';

            return $this->resourceAnalyzer->extractSchemaFromArray($dataNode, $contextClass);
        }

        return SchemaObject::object();
    }

    /**
     * Trace a variable back to its array literal assignment within the current method AST.
     */
    private function traceVariableToArrayInMethod(string $varName, AnalysisContext $ctx): ?Node\Expr\Array_
    {
        if (! $ctx->hasAst()) {
            return null;
        }

        foreach ($ctx->astNode->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;
            if (! $expr instanceof Expr\Assign) {
                continue;
            }

            if (! $expr->var instanceof Expr\Variable || $expr->var->name !== $varName) {
                continue;
            }

            if ($expr->expr instanceof Node\Expr\Array_) {
                return $expr->expr;
            }

            // Array merge via +: $var = [...] + [...]
            if ($expr->expr instanceof Expr\BinaryOp\Plus && $expr->expr->left instanceof Node\Expr\Array_) {
                return $expr->expr->left;
            }

            break;
        }

        return null;
    }

    /**
     * Resolve the schema of an expression argument (variable, method call, etc.).
     */
    private function resolveArgumentSchema(Expr $expr, AnalysisContext $ctx): ?SchemaObject
    {
        // Direct array literal: response(['key' => $value])
        if ($expr instanceof Node\Expr\Array_) {
            return $this->resourceAnalyzer->extractSchemaFromArray($expr, $ctx->controllerClass() ?? '');
        }

        // Variable: $dto = ...; return response($dto)
        if ($expr instanceof Expr\Variable && is_string($expr->name) && $ctx->hasAst()) {
            // Try array tracing first
            $traced = $this->traceVariableToArrayInMethod($expr->name, $ctx);
            if ($traced !== null) {
                return $this->resourceAnalyzer->extractSchemaFromArray($traced, $ctx->controllerClass() ?? '');
            }

            // Try service method return type tracing
            return $this->resolveVariableSchema($expr->name, $ctx);
        }

        return null;
    }

    /**
     * Trace a variable to its assignment and resolve the schema from a service method call.
     * Handles: $dto = $this->service->method(); return response($dto);
     */
    private function resolveVariableSchema(string $varName, AnalysisContext $ctx): ?SchemaObject
    {
        if (! $ctx->hasAst()) {
            return null;
        }

        foreach ($ctx->astNode->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            if (! $stmt->expr instanceof Expr\Assign) {
                continue;
            }

            if (! $stmt->expr->var instanceof Expr\Variable || $stmt->expr->var->name !== $varName) {
                continue;
            }

            $rhs = $stmt->expr->expr;

            // $var = $this->service->method()
            if ($rhs instanceof MethodCall
                && $rhs->var instanceof Expr\PropertyFetch
                && $rhs->var->var instanceof Expr\Variable
                && $rhs->var->var->name === 'this'
                && $rhs->var->name instanceof Node\Identifier
                && $rhs->name instanceof Node\Identifier
                && $ctx->controllerClass() !== null
            ) {
                return $this->resolveServiceMethodReturnType(
                    $ctx->controllerClass(),
                    $rhs->var->name->toString(),
                    $rhs->name->toString()
                );
            }

            break;
        }

        return null;
    }

    /**
     * Resolve the return type schema of a method on a service injected via constructor.
     */
    private function resolveServiceMethodReturnType(string $controllerClass, string $propertyName, string $methodName): ?SchemaObject
    {
        try {
            $refClass = new \ReflectionClass($controllerClass);
            $constructor = $refClass->getConstructor();
            if ($constructor === null) {
                return null;
            }

            // Find the service class from constructor parameter matching the property name
            $serviceClass = null;
            foreach ($constructor->getParameters() as $param) {
                if ($param->getName() === $propertyName && $param->getType() instanceof \ReflectionNamedType) {
                    $serviceClass = $param->getType()->getName();

                    break;
                }
            }

            if ($serviceClass === null || ! class_exists($serviceClass)) {
                return null;
            }

            // Get the method's return type
            $serviceRef = new \ReflectionClass($serviceClass);
            if (! $serviceRef->hasMethod($methodName)) {
                return null;
            }

            $returnType = $serviceRef->getMethod($methodName)->getReturnType();
            if (! $returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
                return null;
            }

            $returnClass = $returnType->getName();

            // Spatie Data
            if (class_exists(\Spatie\LaravelData\Data::class) && is_subclass_of($returnClass, \Spatie\LaravelData\Data::class)) {
                return $this->spatieAnalyzer->analyze($returnClass);
            }

            // JsonResource
            if (is_subclass_of($returnClass, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                return $this->resourceAnalyzer->analyze($returnClass);
            }

            // ClassSchemaResolver
            return $this->classResolver->resolve($returnClass);
        } catch (\Throwable) {
            return null;
        }
    }

    private function descriptionForStatus(int $status): string
    {
        return match ($status) {
            200 => 'Success',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            default => 'Response',
        };
    }

    /**
     * Build a standard error schema for abort() responses.
     */
    private function abortSchema(int $statusCode): SchemaObject
    {
        return SchemaObject::object(
            properties: [
                'message' => new SchemaObject(
                    type: 'string',
                    example: $this->descriptionForStatus($statusCode),
                ),
            ],
            required: ['message'],
        );
    }
}
