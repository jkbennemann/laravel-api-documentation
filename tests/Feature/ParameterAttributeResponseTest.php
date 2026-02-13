<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Analyzers\Response\DataResponseAttributeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Response\JsonResourceAnalyzer;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\RouteInfo;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\AnnotatedResourceController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\CircularResource;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\ParameterAnnotatedResource;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\SimpleJsonResource;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ParameterAttributeResponseTest extends TestCase
{
    private JsonResourceAnalyzer $resourceAnalyzer;

    private DataResponseAttributeAnalyzer $dataResponseAnalyzer;

    private SchemaRegistry $schemaRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaRegistry = new SchemaRegistry;
        $this->resourceAnalyzer = new JsonResourceAnalyzer($this->schemaRegistry);
        $this->dataResponseAnalyzer = new DataResponseAttributeAnalyzer($this->schemaRegistry);
    }

    // ---------------------------------------------------------------
    // Gap 1: JsonResource #[Parameter] attributes are read
    // ---------------------------------------------------------------

    public function test_json_resource_parameter_attributes_produce_correct_schema(): void
    {
        $schema = $this->resourceAnalyzer->analyze(ParameterAnnotatedResource::class);

        expect($schema)->not()->toBeNull();

        // The schema may be a $ref or an inline object — resolve if needed
        $resolved = $this->resolveSchema($schema);

        expect($resolved->type)->toBe('object');
        expect($resolved->properties)->not()->toBeNull();

        $props = $resolved->properties;

        // 'id' should be integer with description and example
        expect($props)->toHaveKey('id');
        expect($props['id']->type)->toBe('integer');
        expect($props['id']->description)->toBe('Resource ID');
        expect($props['id']->example)->toBe(42);

        // 'name' should be string
        expect($props)->toHaveKey('name');
        expect($props['name']->type)->toBe('string');
        expect($props['name']->description)->toBe('Display name');

        // 'score' should be number with float format
        expect($props)->toHaveKey('score');
        expect($props['score']->type)->toBe('number');
        expect($props['score']->format)->toBe('float');

        // 'notes' should be nullable
        expect($props)->toHaveKey('notes');
        expect($props['notes']->nullable)->toBeTrue();
    }

    public function test_json_resource_without_parameter_attributes_still_works(): void
    {
        $schema = $this->resourceAnalyzer->analyze(SimpleJsonResource::class);

        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSchema($schema);

        expect($resolved->type)->toBe('object');
        expect($resolved->properties)->not()->toBeNull();

        // Should still extract properties from toArray via AST
        $props = $resolved->properties;
        expect($props)->toHaveKey('id');
        expect($props)->toHaveKey('name');
    }

    // ---------------------------------------------------------------
    // Gap 2: Non-success (404) response gets enriched schema via DataResponseAttributeAnalyzer
    // ---------------------------------------------------------------

    public function test_non_success_response_with_resource_gets_enriched_schema(): void
    {
        $ctx = $this->makeContext(AnnotatedResourceController::class, 'withErrorResponse');

        $results = $this->dataResponseAnalyzer->extract($ctx);

        // Should have two responses: 200 and 404
        expect($results)->toHaveCount(2);

        $resultsByStatus = [];
        foreach ($results as $result) {
            $resultsByStatus[$result->statusCode] = $result;
        }

        expect($resultsByStatus)->toHaveKey(404);

        $errorSchema = $resultsByStatus[404]->schema;
        expect($errorSchema)->not()->toBeNull();

        $resolved = $this->resolveSchema($errorSchema);

        expect($resolved->properties)->not()->toBeNull();

        // Response is wrapped in 'data' (Laravel default JsonResource $wrap)
        expect($resolved->properties)->toHaveKey('data');
        $inner = $this->resolveSchema($resolved->properties['data']);
        $props = $inner->properties;

        // 'message' should be string with correct description from Parameter attribute
        expect($props)->toHaveKey('message');
        expect($props['message']->type)->toBe('string');
        expect($props['message']->description)->toBe('Human-readable error description');
        expect($props['message']->example)->toBe('Not Found');

        // 'status' should be integer
        expect($props)->toHaveKey('status');
        expect($props['status']->type)->toBe('integer');
        expect($props['status']->description)->toBe('HTTP status code');
        expect($props['status']->example)->toBe(404);
    }

    // ---------------------------------------------------------------
    // Gap 3 + Gap 4: resource: on Parameter produces nested properties
    // ---------------------------------------------------------------

    public function test_resource_reference_on_parameter_produces_nested_properties(): void
    {
        $ctx = $this->makeContext(AnnotatedResourceController::class, 'nestedResource');

        $results = $this->dataResponseAnalyzer->extract($ctx);

        expect($results)->not()->toBeEmpty();

        $result = $results[0];
        expect($result->statusCode)->toBe(200);

        $schema = $result->schema;
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSchema($schema);

        expect($resolved->properties)->not()->toBeNull();

        // Response is wrapped in 'data' (Laravel default JsonResource $wrap)
        expect($resolved->properties)->toHaveKey('data');
        $inner = $this->resolveSchema($resolved->properties['data']);
        $props = $inner->properties;

        // 'id' and 'status' should be simple types
        expect($props)->toHaveKey('id');
        expect($props['id']->type)->toBe('string');

        expect($props)->toHaveKey('status');
        expect($props['status']->type)->toBe('string');

        // 'result' should reference NestedDetailResource — resolve if $ref
        expect($props)->toHaveKey('result');
        $resultProp = $props['result'];

        // It should be nullable
        expect($resultProp->nullable)->toBeTrue();

        // Resolve nested schema (may be $ref or inline)
        $nestedResolved = $this->resolveSchema($resultProp);

        // If it was resolved as a $ref, re-resolve to get actual properties
        if ($nestedResolved->ref !== null) {
            $nestedResolved = $this->schemaRegistry->resolve($nestedResolved->ref);
        }

        expect($nestedResolved->properties)->not()->toBeNull();
        $resultProps = $nestedResolved->properties;

        expect($resultProps)->toHaveKey('street');
        expect($resultProps['street']->type)->toBe('string');
        expect($resultProps['street']->description)->toBe('Street address');

        expect($resultProps)->toHaveKey('city');
        expect($resultProps['city']->type)->toBe('string');
        expect($resultProps['city']->description)->toBe('City name');
    }

    // ---------------------------------------------------------------
    // Circular reference protection
    // ---------------------------------------------------------------

    public function test_circular_resource_references_do_not_cause_infinite_recursion(): void
    {
        $schema = $this->resourceAnalyzer->analyze(CircularResource::class);

        // Should complete without hanging or throwing
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSchema($schema);

        expect($resolved->properties)->not()->toBeNull();
        $props = $resolved->properties;
        expect($props)->toHaveKey('id');
        expect($props)->toHaveKey('parent');

        // Parent should be nullable — the circular ref is just cut off (returns null from recursion guard)
        expect($props['parent']->nullable)->toBeTrue();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function resolveSchema(SchemaObject $schema): SchemaObject
    {
        if ($schema->ref !== null) {
            $resolved = $this->schemaRegistry->resolve($schema->ref);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $schema;
    }

    private function makeContext(string $controller, string $method): AnalysisContext
    {
        $reflection = new \ReflectionMethod($controller, $method);

        // Read attributes from the reflection method
        $attributes = [];
        foreach ($reflection->getAttributes() as $attr) {
            $instance = $attr->newInstance();
            $class = $attr->getName();

            if (isset($attributes[$class])) {
                if (! is_array($attributes[$class])) {
                    $attributes[$class] = [$attributes[$class]];
                }
                $attributes[$class][] = $instance;
            } else {
                $attributes[$class] = $instance;
            }
        }

        $route = new RouteInfo(
            uri: '/test/'.$method,
            methods: ['GET'],
            controller: $controller,
            action: $method,
            middleware: [],
            domain: null,
            pathParameters: [],
            name: null,
        );

        return new AnalysisContext(
            route: $route,
            reflectionMethod: $reflection,
            attributes: $attributes,
        );
    }
}
