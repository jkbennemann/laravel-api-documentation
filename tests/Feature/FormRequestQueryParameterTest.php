<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SearchController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class FormRequestQueryParameterTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_get_route_with_form_request_produces_query_parameters(): void
    {
        Route::get('api/search', [SearchController::class, 'index']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/search']['get'] ?? null;
        expect($operation)->not()->toBeNull();

        // Should have query parameters, NOT a request body
        expect($operation)->not()->toHaveKey('requestBody');
        expect($operation)->toHaveKey('parameters');

        $paramNames = array_map(fn ($p) => $p['name'], $operation['parameters']);
        expect($paramNames)->toContain('q');
        expect($paramNames)->toContain('page');
        expect($paramNames)->toContain('per_page');
        expect($paramNames)->toContain('sort');
    }

    public function test_post_route_with_form_request_produces_request_body(): void
    {
        Route::post('api/posts', [PostController::class, 'store']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/posts']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');
    }

    public function test_get_route_required_rules_marked_required_in_query_params(): void
    {
        Route::get('api/search', [SearchController::class, 'index']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/search']['get'] ?? null;
        $params = collect($operation['parameters'] ?? []);

        $qParam = $params->firstWhere('name', 'q');
        expect($qParam['required'])->toBeTrue();
    }

    public function test_get_route_optional_rules_not_required(): void
    {
        Route::get('api/search', [SearchController::class, 'index']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/search']['get'] ?? null;
        $params = collect($operation['parameters'] ?? []);

        $pageParam = $params->firstWhere('name', 'page');
        expect($pageParam['required'])->toBeFalse();
    }

    public function test_put_route_with_form_request_still_produces_request_body(): void
    {
        Route::put('api/posts/{post}', [PostController::class, 'update']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/posts/{post}']['put'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');
    }
}
