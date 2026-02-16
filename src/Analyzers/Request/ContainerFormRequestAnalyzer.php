<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Request;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Cache\AstCache;
use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Detects FormRequests resolved via the service container in method bodies.
 *
 * Handles patterns like:
 *   $request = resolve(StoreProductRequest::class);
 *   $request = app(StoreProductRequest::class);
 *
 * Also traces into helper methods:
 *   $validated = $this->validateCreateRequest();
 *   // where validateCreateRequest() calls resolve(SomeFormRequest::class)
 */
class ContainerFormRequestAnalyzer implements RequestBodyExtractor
{
    private ValidationRuleMapper $ruleMapper;

    private const QUERY_METHODS = ['GET', 'HEAD'];

    private const CONTAINER_FUNCTIONS = ['resolve', 'app'];

    public function __construct(
        private readonly FormRequestAnalyzer $formRequestAnalyzer,
        array $config = [],
        private readonly ?AstCache $astCache = null,
        private readonly ?SchemaRegistry $schemaRegistry = null,
    ) {
        $this->ruleMapper = new ValidationRuleMapper($config, $schemaRegistry);
    }

    public function extract(AnalysisContext $ctx): ?SchemaResult
    {
        if (in_array($ctx->route->httpMethod(), self::QUERY_METHODS, true)) {
            return null;
        }

        if (! $ctx->hasAst()) {
            return null;
        }

        // First: check directly in the action method body
        $formRequestClass = $this->findContainerResolveInStmts(
            $ctx->astNode->stmts ?? [],
            $ctx->sourceFilePath,
            $ctx,
        );

        // Second: trace into $this->helperMethod() calls
        if ($formRequestClass === null && $ctx->controllerClass() !== null) {
            $formRequestClass = $this->traceHelperMethods($ctx);
        }

        if ($formRequestClass === null) {
            return null;
        }

        $rules = $this->formRequestAnalyzer->extractRules($formRequestClass);
        if (empty($rules)) {
            return null;
        }

        $schema = $this->ruleMapper->mapAllRules($rules);

        if ($this->schemaRegistry !== null) {
            $name = class_basename($formRequestClass);
            $registered = $this->schemaRegistry->registerIfComplex($name, $schema);
            if ($registered instanceof SchemaObject) {
                $schema = $registered;
            }
        }

        $contentType = $this->ruleMapper->hasFileUpload($rules)
            ? 'multipart/form-data'
            : 'application/json';

        return new SchemaResult(
            schema: $schema,
            description: 'Request body',
            contentType: $contentType,
            source: 'container_form_request:'.class_basename($formRequestClass),
        );
    }

    /**
     * Scan AST statements for resolve(FormRequest::class) or app(FormRequest::class) calls.
     *
     * @param  Node[]  $stmts
     */
    private function findContainerResolveInStmts(array $stmts, ?string $sourceFilePath, AnalysisContext $ctx): ?string
    {
        $nodeFinder = new NodeFinder;
        $funcCalls = $nodeFinder->findInstanceOf($stmts, FuncCall::class);

        foreach ($funcCalls as $call) {
            if (! $call->name instanceof Name) {
                continue;
            }

            $funcName = $call->name->toString();
            if (! in_array($funcName, self::CONTAINER_FUNCTIONS, true)) {
                continue;
            }

            if (empty($call->args)) {
                continue;
            }

            $firstArg = $call->args[0]->value ?? null;

            // resolve(SomeFormRequest::class) or app(SomeFormRequest::class)
            if ($firstArg instanceof ClassConstFetch
                && $firstArg->name instanceof Node\Identifier
                && $firstArg->name->toString() === 'class'
                && $firstArg->class instanceof Name
            ) {
                $className = $firstArg->class->toString();
                $resolved = $this->resolveClassNameFromFile($className, $sourceFilePath, $ctx);

                if ($resolved !== null) {
                    try {
                        if (is_subclass_of($resolved, FormRequest::class)) {
                            return $resolved;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find $this->method() calls in the action body, then trace into those helper methods.
     */
    private function traceHelperMethods(AnalysisContext $ctx): ?string
    {
        return $this->findInThisMethodCalls(
            $ctx->astNode->stmts ?? [],
            $ctx->controllerClass(),
            $ctx,
            depth: 0,
        );
    }

    private const MAX_TRACE_DEPTH = 3;

    /**
     * Scan statements for $this->method() calls and trace into their bodies recursively.
     *
     * @param  Node[]  $stmts
     */
    private function findInThisMethodCalls(array $stmts, string $controllerClass, AnalysisContext $ctx, int $depth): ?string
    {
        if ($depth >= self::MAX_TRACE_DEPTH) {
            return null;
        }

        $nodeFinder = new NodeFinder;
        $methodCalls = $nodeFinder->findInstanceOf($stmts, MethodCall::class);

        foreach ($methodCalls as $call) {
            // Only $this->method() calls
            if (! $call->var instanceof Expr\Variable
                || $call->var->name !== 'this'
                || ! $call->name instanceof Node\Identifier
            ) {
                continue;
            }

            $helperName = $call->name->toString();
            $result = $this->traceIntoHelperMethod($controllerClass, $helperName, $ctx, $depth + 1);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Parse a helper method's body and look for container-resolved FormRequests.
     * If not found directly, recursively traces into $this->method() calls.
     */
    private function traceIntoHelperMethod(string $controllerClass, string $methodName, AnalysisContext $ctx, int $depth): ?string
    {
        try {
            $refClass = new \ReflectionClass($controllerClass);
            if (! $refClass->hasMethod($methodName)) {
                return null;
            }

            // Quick check: if the return type is a FormRequest subclass, use it directly
            $refMethod = $refClass->getMethod($methodName);
            $returnType = $refMethod->getReturnType();
            if ($returnType instanceof \ReflectionNamedType && ! $returnType->isBuiltin()) {
                try {
                    if (is_subclass_of($returnType->getName(), FormRequest::class)) {
                        return $returnType->getName();
                    }
                } catch (\Throwable) {
                    // Continue with AST tracing
                }
            }

            $declaringClass = $refMethod->getDeclaringClass();
            $fileName = $declaringClass->getFileName();

            if (! $fileName || ! file_exists($fileName)) {
                return null;
            }

            $stmts = $this->parseFile($fileName);
            if ($stmts === null) {
                return null;
            }

            // Find the target method in the AST
            $nodeFinder = new NodeFinder;
            $classMethods = $nodeFinder->findInstanceOf($stmts, ClassMethod::class);

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

            $helperStmts = $targetMethod->stmts ?? [];

            // Look for resolve()/app() directly inside the helper method
            $found = $this->findContainerResolveInStmts($helperStmts, $fileName, $ctx);
            if ($found !== null) {
                return $found;
            }

            // Recurse: trace $this->method() calls inside this helper
            return $this->findInThisMethodCalls($helperStmts, $controllerClass, $ctx, $depth);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse a file using AstCache if available, otherwise use a fresh parser.
     *
     * @return Node\Stmt[]|null
     */
    private function parseFile(string $filePath): ?array
    {
        if ($this->astCache !== null) {
            return $this->astCache->parseFile($filePath);
        }

        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return null;
            }
            $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();

            return $parser->parse($code);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a short class name to a FQCN using the given file's use-statements.
     */
    private function resolveClassNameFromFile(string $shortName, ?string $filePath, AnalysisContext $ctx): ?string
    {
        if (class_exists($shortName)) {
            return $shortName;
        }

        // Check use-statement map from the source file
        if ($filePath !== null) {
            $useMap = $this->resolveUseStatements($filePath);
            if (isset($useMap[$shortName])) {
                $fqcn = $useMap[$shortName];
                if (class_exists($fqcn)) {
                    return $fqcn;
                }
            }
        }

        // Try with controller's namespace
        if ($ctx->controllerClass()) {
            $namespace = substr($ctx->controllerClass(), 0, (int) strrpos($ctx->controllerClass(), '\\'));
            $fqcn = $namespace.'\\'.$shortName;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * Parse use statements from a PHP file.
     *
     * @return array<string, string>
     */
    private function resolveUseStatements(string $filePath): array
    {
        static $cache = [];

        if (isset($cache[$filePath])) {
            return $cache[$filePath];
        }

        $map = [];

        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return $cache[$filePath] = $map;
            }

            // Simple regex-based use-statement extraction (fast, no AST needed)
            if (preg_match_all('/^use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?\s*;/m', $code, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $fqcn = $match[1];
                    $alias = $match[2] ?? substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
                    $map[$alias] = $fqcn;
                }
            }
        } catch (\Throwable) {
            // Ignore
        }

        return $cache[$filePath] = $map;
    }
}
