<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\StatusController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ApiKeyAuthDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable API key auth plugin for all tests by default
        config()->set('api-documentation.security.api_key.enabled', true);
    }

    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_auth_apikey_middleware_produces_api_key_scheme(): void
    {
        Route::get('api/users', [StatusController::class, 'index'])
            ->middleware(['auth.apikey']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('security');
        expect($operation['security'])->toBe([['apiKeyAuth' => []]]);

        // Verify the scheme is registered in components
        expect($spec['components']['securitySchemes']['apiKeyAuth'])->toBe([
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-API-KEY',
            'description' => 'API key passed via request header',
        ]);
    }

    public function test_no_auth_middleware_has_no_security(): void
    {
        Route::get('api/users', [StatusController::class, 'index']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation)->not()->toHaveKey('security');
    }

    public function test_custom_header_name_via_config(): void
    {
        config()->set('api-documentation.security.api_key.header', 'X-Custom-Key');

        Route::get('api/users', [StatusController::class, 'index'])
            ->middleware(['auth.apikey']);

        $spec = $this->generateSpec();

        expect($spec['components']['securitySchemes']['apiKeyAuth']['name'])->toBe('X-Custom-Key');
    }

    public function test_disabled_via_config_produces_no_security(): void
    {
        config()->set('api-documentation.security.api_key.enabled', false);

        Route::get('api/users', [StatusController::class, 'index'])
            ->middleware(['auth.apikey']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation)->not()->toHaveKey('security');
    }
}
