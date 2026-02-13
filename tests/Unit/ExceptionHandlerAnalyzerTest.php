<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Analyzers\Error\ExceptionHandlerAnalyzer;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\RouteInfo;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

function parseMethodFromCode(string $code): ?ClassMethod
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $stmts = $parser->parse($code);
    $finder = new NodeFinder;

    return $finder->findFirstInstanceOf($stmts, ClassMethod::class);
}

function makeContextWithAst(ClassMethod $method, ?string $sourceFilePath = null): AnalysisContext
{
    $route = new RouteInfo(
        uri: 'api/test',
        methods: ['GET'],
        controller: null,
        action: null,
        middleware: [],
        domain: null,
        pathParameters: [],
        name: null,
    );

    return new AnalysisContext(
        route: $route,
        astNode: $method,
        sourceFilePath: $sourceFilePath,
    );
}

it('detects NotFoundHttpException as 404', function () {
    $code = '<?php
    class Ctrl {
        public function show() {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("Not found");
        }
    }';

    $method = parseMethodFromCode($code);
    $ctx = makeContextWithAst($method);

    $analyzer = new ExceptionHandlerAnalyzer;
    $results = $analyzer->extract($ctx);

    $statusCodes = array_map(fn ($r) => $r->statusCode, $results);
    expect($statusCodes)->toContain(404);
});

it('detects AccessDeniedHttpException as 403', function () {
    $code = '<?php
    class Ctrl {
        public function update() {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException("Forbidden");
        }
    }';

    $method = parseMethodFromCode($code);
    $ctx = makeContextWithAst($method);

    $analyzer = new ExceptionHandlerAnalyzer;
    $results = $analyzer->extract($ctx);

    $statusCodes = array_map(fn ($r) => $r->statusCode, $results);
    expect($statusCodes)->toContain(403);
});

it('detects ModelNotFoundException as 404', function () {
    $code = '<?php
    class Ctrl {
        public function show() {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Model not found");
        }
    }';

    $method = parseMethodFromCode($code);
    $ctx = makeContextWithAst($method);

    $analyzer = new ExceptionHandlerAnalyzer;
    $results = $analyzer->extract($ctx);

    $statusCodes = array_map(fn ($r) => $r->statusCode, $results);
    expect($statusCodes)->toContain(404);
});

it('detects multiple exceptions in same method', function () {
    $code = '<?php
    class Ctrl {
        public function update() {
            if (true) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
            }
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }
    }';

    $method = parseMethodFromCode($code);
    $ctx = makeContextWithAst($method);

    $analyzer = new ExceptionHandlerAnalyzer;
    $results = $analyzer->extract($ctx);

    $statusCodes = array_map(fn ($r) => $r->statusCode, $results);
    expect($statusCodes)->toContain(404)
        ->and($statusCodes)->toContain(403);
});

it('returns empty for method with no throws', function () {
    $code = '<?php
    class Ctrl {
        public function index() {
            return [];
        }
    }';

    $method = parseMethodFromCode($code);
    $ctx = makeContextWithAst($method);

    $analyzer = new ExceptionHandlerAnalyzer;
    $results = $analyzer->extract($ctx);

    expect($results)->toBeEmpty();
});

it('returns empty when context has no AST', function () {
    $route = new RouteInfo(
        uri: 'api/test',
        methods: ['GET'],
        controller: null,
        action: null,
        middleware: [],
        domain: null,
        pathParameters: [],
        name: null,
    );

    $ctx = new AnalysisContext(route: $route);

    $analyzer = new ExceptionHandlerAnalyzer;
    $results = $analyzer->extract($ctx);

    expect($results)->toBeEmpty();
});
