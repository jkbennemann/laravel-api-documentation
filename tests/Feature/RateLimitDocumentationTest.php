<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\StatusController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class RateLimitDocumentationTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_throttle_middleware_adds_429_response(): void
    {
        Route::get('api/status', StatusController::class)->middleware('throttle:60,1');

        $spec = $this->generateSpec();

        $op = $spec['paths']['/api/status']['get'] ?? null;
        expect($op)->not()->toBeNull();
        expect($op['responses'])->toHaveKey('429');
        expect($op['responses']['429']['description'])->toContain('Too many requests');
    }

    public function test_429_response_has_rate_limit_headers(): void
    {
        Route::get('api/status', StatusController::class)->middleware('throttle:100,1');

        $spec = $this->generateSpec();

        $response429 = $spec['paths']['/api/status']['get']['responses']['429'];

        expect($response429)->toHaveKey('headers');
        expect($response429['headers'])->toHaveKey('X-RateLimit-Limit');
        expect($response429['headers'])->toHaveKey('X-RateLimit-Remaining');
        expect($response429['headers'])->toHaveKey('Retry-After');

        // Check the limit matches the middleware config
        expect($response429['headers']['X-RateLimit-Limit']['example'])->toBe(100);
    }

    public function test_no_throttle_no_429(): void
    {
        Route::get('api/status', StatusController::class);

        $spec = $this->generateSpec();

        $op = $spec['paths']['/api/status']['get'];
        expect($op['responses'])->not()->toHaveKey('429');
    }

    public function test_named_throttle_uses_default_limit(): void
    {
        Route::get('api/status', StatusController::class)->middleware('throttle:api');

        $spec = $this->generateSpec();

        $response429 = $spec['paths']['/api/status']['get']['responses']['429'];
        // Named rate limiter 'api' - uses default limit of 60
        expect($response429['headers']['X-RateLimit-Limit']['example'])->toBe(60);
    }

    public function test_bare_throttle_middleware(): void
    {
        Route::get('api/status', StatusController::class)->middleware('throttle');

        $spec = $this->generateSpec();

        expect($spec['paths']['/api/status']['get']['responses'])->toHaveKey('429');
    }
}
