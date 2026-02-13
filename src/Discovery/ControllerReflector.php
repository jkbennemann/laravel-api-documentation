<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Discovery;

use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\RouteInfo;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

class ControllerReflector
{
    private Parser $parser;

    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder;
    }

    public function buildContext(RouteInfo $route, ?\Closure $routeClosure = null): AnalysisContext
    {
        $controller = $route->controllerClass();
        $action = $route->actionMethod();

        if ($controller === null && $routeClosure !== null) {
            return $this->buildClosureContext($route, $routeClosure);
        }

        if ($controller === null) {
            return new AnalysisContext(route: $route);
        }

        $reflectionMethod = null;
        $astNode = null;
        $sourceFilePath = null;
        $attributes = [];

        try {
            $reflectionClass = new ReflectionClass($controller);
            $sourceFilePath = $reflectionClass->getFileName() ?: null;

            if ($action !== null && $reflectionClass->hasMethod($action)) {
                $reflectionMethod = $reflectionClass->getMethod($action);
                $attributes = $this->extractAttributes($reflectionMethod, $reflectionClass);
            }
        } catch (\ReflectionException $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Could not reflect controller {$controller}: {$e->getMessage()}");
            }
        }

        if ($sourceFilePath !== null && $action !== null) {
            $astNode = $this->parseMethodAst($sourceFilePath, $action);
        }

        return new AnalysisContext(
            route: $route,
            reflectionMethod: $reflectionMethod,
            astNode: $astNode,
            sourceFilePath: $sourceFilePath,
            attributes: $attributes,
        );
    }

    private function buildClosureContext(RouteInfo $route, \Closure $closure): AnalysisContext
    {
        try {
            $rf = new ReflectionFunction($closure);
            $filePath = $rf->getFileName() ?: null;
            $startLine = $rf->getStartLine();
            $attributes = $this->extractClosureAttributes($rf);

            $astNode = null;
            if ($filePath !== null && $startLine !== false) {
                $astNode = $this->parseClosureAst($filePath, $startLine);
            }

            return new AnalysisContext(
                route: $route,
                astNode: $astNode,
                sourceFilePath: $filePath,
                attributes: $attributes,
                reflectionFunction: $rf,
            );
        } catch (\ReflectionException $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Could not reflect closure route: {$e->getMessage()}");
            }

            return new AnalysisContext(route: $route);
        }
    }

    /**
     * Extract PHP 8 attributes from method and class.
     *
     * @return array<string, mixed>
     */
    private function extractAttributes(ReflectionMethod $method, ReflectionClass $class): array
    {
        $attributes = [];

        // Class-level attributes
        foreach ($class->getAttributes() as $attr) {
            try {
                $instance = $attr->newInstance();
                $name = $attr->getName();

                if (isset($attributes[$name]) && $attr->isRepeated()) {
                    if (! is_array($attributes[$name])) {
                        $attributes[$name] = [$attributes[$name]];
                    }
                    $attributes[$name][] = $instance;
                } else {
                    $attributes[$name] = $instance;
                }
            } catch (\Throwable) {
                // Skip attributes that can't be instantiated
            }
        }

        // Method-level attributes (override class-level)
        foreach ($method->getAttributes() as $attr) {
            try {
                $instance = $attr->newInstance();
                $name = $attr->getName();

                if (isset($attributes[$name]) && $attr->isRepeated()) {
                    if (! is_array($attributes[$name])) {
                        $attributes[$name] = [$attributes[$name]];
                    }
                    $attributes[$name][] = $instance;
                } else {
                    $attributes[$name] = $instance;
                }
            } catch (\Throwable) {
                // Skip attributes that can't be instantiated
            }
        }

        return $attributes;
    }

    /**
     * Extract PHP 8 attributes from a closure's ReflectionFunction.
     *
     * @return array<string, mixed>
     */
    private function extractClosureAttributes(ReflectionFunction $rf): array
    {
        $attributes = [];

        foreach ($rf->getAttributes() as $attr) {
            try {
                $instance = $attr->newInstance();
                $name = $attr->getName();

                if (isset($attributes[$name]) && $attr->isRepeated()) {
                    if (! is_array($attributes[$name])) {
                        $attributes[$name] = [$attributes[$name]];
                    }
                    $attributes[$name][] = $instance;
                } else {
                    $attributes[$name] = $instance;
                }
            } catch (\Throwable) {
                // Skip attributes that can't be instantiated
            }
        }

        return $attributes;
    }

    /**
     * Parse a file and find the Closure or ArrowFunction AST node at the given start line.
     * ArrowFunctions are converted to Closure nodes for uniform `stmts` access.
     */
    private function parseClosureAst(string $filePath, int $startLine): ?Closure
    {
        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return null;
            }

            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return null;
            }

            // Find Closure nodes
            $closures = $this->nodeFinder->findInstanceOf($stmts, Closure::class);
            foreach ($closures as $closure) {
                if ($closure->getStartLine() === $startLine) {
                    return $closure;
                }
            }

            // Find ArrowFunction nodes and convert to Closure
            $arrows = $this->nodeFinder->findInstanceOf($stmts, ArrowFunction::class);
            foreach ($arrows as $arrow) {
                if ($arrow->getStartLine() === $startLine) {
                    return $this->arrowToClosure($arrow);
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Closure AST parsing failed for {$filePath}:{$startLine}: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Convert an ArrowFunction to a Closure node wrapping the expression in a Return_ statement.
     */
    private function arrowToClosure(ArrowFunction $arrow): Closure
    {
        return new Closure([
            'params' => $arrow->params,
            'returnType' => $arrow->returnType,
            'stmts' => [new Return_($arrow->expr)],
            'static' => $arrow->static,
            'attrGroups' => $arrow->attrGroups,
        ]);
    }

    private function parseMethodAst(string $filePath, string $methodName): ?ClassMethod
    {
        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return null;
            }

            $stmts = $this->parser->parse($code);
            if ($stmts === null) {
                return null;
            }

            $methods = $this->nodeFinder->findInstanceOf($stmts, ClassMethod::class);

            foreach ($methods as $method) {
                if ($method->name->toString() === $methodName) {
                    return $method;
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Method AST parsing failed for {$filePath}::{$methodName}: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Detect if a controller action type-hints a FormRequest.
     */
    public function detectFormRequest(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $className = $type->getName();
                try {
                    if (is_subclass_of($className, \Illuminate\Foundation\Http\FormRequest::class)) {
                        return $className;
                    }
                } catch (\Throwable) {
                    // Class doesn't exist
                }
            }
        }

        return null;
    }

    /**
     * Detect if a parameter type-hints a Spatie Data object.
     */
    public function detectSpatieData(ReflectionMethod $method): ?string
    {
        if (! class_exists(\Spatie\LaravelData\Data::class)) {
            return null;
        }

        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $className = $type->getName();
                try {
                    if (is_subclass_of($className, \Spatie\LaravelData\Data::class)) {
                        return $className;
                    }
                } catch (\Throwable) {
                    // Class doesn't exist
                }
            }
        }

        return null;
    }
}
