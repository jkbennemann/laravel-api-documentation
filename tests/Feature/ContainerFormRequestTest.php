<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\ContainerResolvedController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ContainerFormRequestTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_resolve_form_request_extracts_request_body(): void
    {
        Route::post('api/store-resolve', [ContainerResolvedController::class, 'storeWithResolve']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/store-resolve']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('parameter_1');
        expect($schema['properties'])->toHaveKey('parameter_2');
        expect($schema['required'])->toContain('parameter_1');
    }

    public function test_app_form_request_extracts_request_body(): void
    {
        Route::post('api/store-app', [ContainerResolvedController::class, 'storeWithApp']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/store-app']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('parameter_1');
        expect($schema['properties'])->toHaveKey('parameter_2');
    }

    public function test_helper_method_with_resolve_extracts_request_body(): void
    {
        Route::post('api/store-helper', [ContainerResolvedController::class, 'storeViaHelper']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/store-helper']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('parameter_1');
        expect($schema['properties'])->toHaveKey('parameter_2');
        expect($schema['required'])->toContain('parameter_1');
    }

    public function test_nested_helper_with_resolve_extracts_request_body(): void
    {
        Route::post('api/store-nested', [ContainerResolvedController::class, 'storeViaNested']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/store-nested']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('parameter_1');
        expect($schema['properties'])->toHaveKey('parameter_2');
        expect($schema['required'])->toContain('parameter_1');
    }

    public function test_resolve_form_request_triggers_422_response(): void
    {
        Route::post('api/store-resolve', [ContainerResolvedController::class, 'storeWithResolve']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/store-resolve']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('422');
    }

    public function test_nested_helper_triggers_422_response(): void
    {
        Route::post('api/store-nested', [ContainerResolvedController::class, 'storeViaNested']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/store-nested']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('422');
    }

    public function test_abort_only_method_produces_error_response_with_schema(): void
    {
        Route::put('api/abort-only', [ContainerResolvedController::class, 'abortOnly']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/abort-only']['put'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('405');

        // Should have an error schema with message property
        $schema = $operation['responses']['405']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        expect($schema['properties'])->toHaveKey('message');
    }

    public function test_abort_only_method_does_not_produce_default_200(): void
    {
        Route::put('api/abort-only', [ContainerResolvedController::class, 'abortOnly']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/abort-only']['put'] ?? null;
        expect($operation)->not()->toBeNull();

        // Should NOT have a fallback 200 response
        expect($operation['responses'])->not()->toHaveKey('200');
    }

    public function test_abort_501_produces_not_implemented_response(): void
    {
        Route::get('api/not-implemented', [ContainerResolvedController::class, 'abortNotImplemented']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/not-implemented']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('501');

        $response = $operation['responses']['501'];
        expect($response['description'])->toBe('Not Implemented');
    }
}
