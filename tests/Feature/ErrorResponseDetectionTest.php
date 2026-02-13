<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * Tests auto-detection of error responses (401, 404, 422) through the full v2 pipeline.
 */
class ErrorResponseDetectionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    // -----------------------------------------------------------------
    // 1. auth middleware triggers 401 response
    // -----------------------------------------------------------------

    public function test_auth_middleware_triggers_401_response(): void
    {
        Route::middleware('auth:api')->get('api/protected', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/protected']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('401');
    }

    // -----------------------------------------------------------------
    // 2. FormRequest triggers 422 response
    // -----------------------------------------------------------------

    public function test_form_request_triggers_422_response(): void
    {
        Route::post('api/posts', [PostController::class, 'store']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/posts']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('422');
    }

    // -----------------------------------------------------------------
    // 3. Model binding triggers 404 response
    // -----------------------------------------------------------------

    public function test_model_binding_triggers_404_response(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/posts/{post}']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('404');
    }

    // -----------------------------------------------------------------
    // 4. Route without auth has no 401
    // -----------------------------------------------------------------

    public function test_route_without_auth_has_no_401(): void
    {
        Route::get('api/public', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/public']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->not()->toHaveKey('401');
    }

    // -----------------------------------------------------------------
    // 5. Route without FormRequest has no 422
    // -----------------------------------------------------------------

    public function test_route_without_form_request_has_no_422(): void
    {
        Route::get('api/simple', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/simple']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->not()->toHaveKey('422');
    }
}
