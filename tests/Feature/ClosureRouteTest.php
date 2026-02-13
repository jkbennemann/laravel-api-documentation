<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ClosureRouteTest extends TestCase
{
    private function generateSpec(array $configOverrides = []): array
    {
        app(SchemaRegistry::class)->reset();

        $config = array_merge(config('api-documentation'), $configOverrides);

        $discovery = new RouteDiscovery(app('router'), $config);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, $config);
    }

    public function test_closure_route_generates_basic_spec(): void
    {
        Route::get('api/health', function (): JsonResponse {
            return response()->json(['status' => 'ok']);
        });

        $spec = $this->generateSpec(['include_closure_routes' => true]);

        $operation = $spec['paths']['/api/health']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('operationId');
        expect($operation['responses'])->toHaveKey('200');
    }

    public function test_closure_route_infers_tag_from_uri(): void
    {
        Route::get('api/status', function (): JsonResponse {
            return response()->json(['status' => 'ok']);
        });

        $spec = $this->generateSpec(['include_closure_routes' => true]);

        $operation = $spec['paths']['/api/status']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['tags'][0])->toBe('Status');
    }

    public function test_closure_route_with_inline_validation(): void
    {
        Route::post('api/items', function (Request $request): JsonResponse {
            $validated = $request->validate([
                'name' => 'required|string',
                'quantity' => 'required|integer',
            ]);

            return response()->json($validated, 201);
        });

        $spec = $this->generateSpec(['include_closure_routes' => true]);

        $operation = $spec['paths']['/api/items']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        expect($schema['properties'])->toHaveKey('name');
        expect($schema['properties'])->toHaveKey('quantity');
    }

    public function test_arrow_function_route_generates_spec(): void
    {
        Route::get('api/ping', fn (): JsonResponse => response()->json(['pong' => true]));

        $spec = $this->generateSpec(['include_closure_routes' => true]);

        $operation = $spec['paths']['/api/ping']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('200');
    }

    public function test_closure_routes_excluded_by_default(): void
    {
        Route::get('api/health', fn () => response()->json(['ok' => true]));

        $spec = $this->generateSpec();

        expect($spec['paths'] ?? [])->not()->toHaveKey('/api/health');
    }

    public function test_closure_route_nested_uri_infers_tag(): void
    {
        Route::get('api/v2/users/stats', fn (): JsonResponse => response()->json(['count' => 42]));

        $spec = $this->generateSpec(['include_closure_routes' => true]);

        $operation = $spec['paths']['/api/v2/users/stats']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['tags'][0])->toBe('Stats');
    }
}
