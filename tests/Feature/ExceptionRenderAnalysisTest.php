<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PaymentController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ExceptionRenderAnalysisTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_custom_exception_render_extracts_status_code(): void
    {
        Route::post('api/charge', [PaymentController::class, 'charge']);

        $spec = $this->generateSpec();

        $responses = $spec['paths']['/api/charge']['post']['responses'] ?? [];
        expect($responses)->toHaveKey('402');
    }

    public function test_custom_exception_render_extracts_schema(): void
    {
        Route::post('api/charge', [PaymentController::class, 'charge']);

        $spec = $this->generateSpec();

        $errorResponse = $spec['paths']['/api/charge']['post']['responses']['402'] ?? null;
        expect($errorResponse)->not()->toBeNull();

        $schema = $errorResponse['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('error');
        expect($schema['properties'])->toHaveKey('message');
        expect($schema['properties']['error']['example'])->toBe('insufficient_balance');
    }
}
