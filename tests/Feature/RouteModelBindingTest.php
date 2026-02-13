<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\ProductController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\UserController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class RouteModelBindingTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    private function getPathParameter(array $spec, string $path, string $method, string $paramName): ?array
    {
        $parameters = $spec['paths'][$path][$method]['parameters'] ?? [];

        foreach ($parameters as $param) {
            if ($param['name'] === $paramName) {
                return $param;
            }
        }

        return null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('api-documentation.routes.prefixes', ['api']);
        config()->set('api-documentation.routes.middleware', []);
    }

    public function test_infers_slug_type_from_explicit_binding_field(): void
    {
        Route::get('api/products/{product:slug}', [ProductController::class, 'show']);

        $spec = $this->generateSpec();
        $param = $this->getPathParameter($spec, '/api/products/{product}', 'get', 'product');

        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema'])->toHaveKey('pattern');
        expect($param['description'])->toBe('Resolved by slug');
    }

    public function test_infers_slug_type_from_model_get_route_key_name(): void
    {
        // Product model has getRouteKeyName() returning 'slug'
        Route::get('api/products/{product}', [ProductController::class, 'show']);

        $spec = $this->generateSpec();
        $param = $this->getPathParameter($spec, '/api/products/{product}', 'get', 'product');

        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema'])->toHaveKey('pattern');
    }

    public function test_default_id_binding_stays_string(): void
    {
        // User model uses default getRouteKeyName() which returns 'id'
        Route::get('api/users/{user}', [UserController::class, 'show']);

        $spec = $this->generateSpec();
        $param = $this->getPathParameter($spec, '/api/users/{user}', 'get', 'user');

        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
    }

    public function test_maps_uuid_binding_field_to_uuid_format(): void
    {
        Route::get('api/products/{product:uuid}', [ProductController::class, 'show']);

        $spec = $this->generateSpec();
        $param = $this->getPathParameter($spec, '/api/products/{product}', 'get', 'product');

        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema']['format'])->toBe('uuid');
    }

    public function test_maps_email_binding_field_to_email_format(): void
    {
        Route::get('api/products/{product:email}', [ProductController::class, 'show']);

        $spec = $this->generateSpec();
        $param = $this->getPathParameter($spec, '/api/products/{product}', 'get', 'product');

        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema']['format'])->toBe('email');
    }

    public function test_where_constraint_takes_precedence_over_binding_field(): void
    {
        Route::get('api/products/{product:slug}', [ProductController::class, 'show'])
            ->where('product', '[0-9]+');

        $spec = $this->generateSpec();
        $param = $this->getPathParameter($spec, '/api/products/{product}', 'get', 'product');

        // where() constraint says numeric → integer wins over slug inference
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('integer');
    }
}
