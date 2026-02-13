<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\RequestMethodCallController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class RequestMethodCallDetectionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_request_integer_detected_as_query_param(): void
    {
        Route::get('api/items', [RequestMethodCallController::class, 'withInteger']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/items']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('parameters');

        $params = collect($operation['parameters']);
        $pageParam = $params->firstWhere('name', 'page');
        $limitParam = $params->firstWhere('name', 'limit');

        expect($pageParam)->not()->toBeNull();
        expect($pageParam['schema']['type'])->toBe('integer');
        expect($pageParam['in'])->toBe('query');
        expect($pageParam['schema']['default'] ?? null)->toBe(1);

        expect($limitParam)->not()->toBeNull();
        expect($limitParam['schema']['type'])->toBe('integer');
    }

    public function test_request_boolean_detected(): void
    {
        Route::get('api/items', [RequestMethodCallController::class, 'withBoolean']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/items']['get'] ?? null;
        $params = collect($operation['parameters'] ?? []);
        $activeParam = $params->firstWhere('name', 'active');

        expect($activeParam)->not()->toBeNull();
        expect($activeParam['schema']['type'])->toBe('boolean');
    }

    public function test_request_float_detected_as_number(): void
    {
        Route::get('api/locations', [RequestMethodCallController::class, 'withFloat']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/locations']['get'] ?? null;
        $params = collect($operation['parameters'] ?? []);
        $latParam = $params->firstWhere('name', 'latitude');

        expect($latParam)->not()->toBeNull();
        expect($latParam['schema']['type'])->toBe('number');
        expect($latParam['schema']['format'])->toBe('double');
    }

    public function test_not_detected_on_post_routes(): void
    {
        Route::post('api/items', [RequestMethodCallController::class, 'postEndpoint']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/items']['post'] ?? null;
        expect($operation)->not()->toBeNull();

        // Should not have query parameters for POST routes
        $params = collect($operation['parameters'] ?? []);
        $pageParam = $params->firstWhere('name', 'page');
        expect($pageParam)->toBeNull();
    }

    public function test_path_parameters_excluded(): void
    {
        Route::get('api/items/{id}', [RequestMethodCallController::class, 'withPathParam']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/items/{id}']['get'] ?? null;
        expect($operation)->not()->toBeNull();

        $params = collect($operation['parameters'] ?? []);

        // 'format' should be a query param
        $formatParam = $params->where('in', 'query')->firstWhere('name', 'format');
        expect($formatParam)->not()->toBeNull();

        // 'id' should only appear as path param, not query
        $idQueryParam = $params->where('in', 'query')->firstWhere('name', 'id');
        expect($idQueryParam)->toBeNull();
    }

    public function test_request_date_detected(): void
    {
        Route::get('api/events', [RequestMethodCallController::class, 'withDate']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/events']['get'] ?? null;
        $params = collect($operation['parameters'] ?? []);
        $sinceParam = $params->firstWhere('name', 'since');

        expect($sinceParam)->not()->toBeNull();
        expect($sinceParam['schema']['type'])->toBe('string');
        expect($sinceParam['schema']['format'])->toBe('date-time');
    }

    public function test_request_string_methods_detected(): void
    {
        Route::get('api/search', [RequestMethodCallController::class, 'withString']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/search']['get'] ?? null;
        $params = collect($operation['parameters'] ?? []);

        $nameParam = $params->firstWhere('name', 'name');
        expect($nameParam)->not()->toBeNull();
        expect($nameParam['schema']['type'])->toBe('string');

        $filterParam = $params->firstWhere('name', 'filter');
        expect($filterParam)->not()->toBeNull();
        expect($filterParam['schema']['type'])->toBe('string');
    }
}
