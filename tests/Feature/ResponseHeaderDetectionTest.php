<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\HeaderController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ResponseHeaderDetectionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_single_header_detected(): void
    {
        Route::get('api/posts/{post}', [HeaderController::class, 'withSingleHeader']);

        $spec = $this->generateSpec();

        $response = $spec['paths']['/api/posts/{post}']['get']['responses']['200'] ?? null;
        expect($response)->not()->toBeNull();
        expect($response)->toHaveKey('headers');
        expect($response['headers'])->toHaveKey('X-Request-Id');
    }

    public function test_multiple_headers_detected(): void
    {
        Route::get('api/posts/{post}', [HeaderController::class, 'withMultipleHeaders']);

        $spec = $this->generateSpec();

        $response = $spec['paths']['/api/posts/{post}']['get']['responses']['200'] ?? null;
        expect($response)->not()->toBeNull();
        expect($response)->toHaveKey('headers');
        expect($response['headers'])->toHaveKey('X-Request-Id');
        expect($response['headers'])->toHaveKey('X-Rate-Limit-Remaining');
    }

    public function test_no_headers_when_none_set(): void
    {
        Route::get('api/status', [HeaderController::class, 'withNoHeaders']);

        $spec = $this->generateSpec();

        $response = $spec['paths']['/api/status']['get']['responses']['200'] ?? null;
        expect($response)->not()->toBeNull();
        expect($response)->not()->toHaveKey('headers');
    }

    public function test_header_schema_is_string(): void
    {
        Route::get('api/posts/{post}', [HeaderController::class, 'withSingleHeader']);

        $spec = $this->generateSpec();

        $headers = $spec['paths']['/api/posts/{post}']['get']['responses']['200']['headers'] ?? [];
        $headerDef = $headers['X-Request-Id'] ?? null;
        expect($headerDef)->not()->toBeNull();
        expect($headerDef['schema']['type'])->toBe('string');
    }
}
