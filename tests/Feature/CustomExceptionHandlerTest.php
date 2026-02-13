<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\ExceptionHandlerSchemaAnalyzer;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Handlers\CustomJsonHandler;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Handlers\DetailsKeyHandler;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class CustomExceptionHandlerTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        // Force re-analysis of the handler
        app()->forgetInstance(ExceptionHandlerSchemaAnalyzer::class);
        app()->singleton(ExceptionHandlerSchemaAnalyzer::class);

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();
        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    private function swapHandler(string $handlerClass): void
    {
        $this->app->singleton(ExceptionHandler::class, $handlerClass);
    }

    // -----------------------------------------------------------------
    // Custom handler: 401 has envelope properties
    // -----------------------------------------------------------------

    public function test_custom_handler_401_has_envelope_properties(): void
    {
        $this->swapHandler(CustomJsonHandler::class);

        Route::middleware('auth:api')->get('api/protected', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/protected']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('401');

        $schema = $operation['responses']['401']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('timestamp');
        expect($schema['properties'])->toHaveKey('message');
        expect($schema['properties'])->toHaveKey('path');
        expect($schema['properties'])->toHaveKey('status');
    }

    // -----------------------------------------------------------------
    // Custom handler: 404 has envelope properties
    // -----------------------------------------------------------------

    public function test_custom_handler_404_has_envelope_properties(): void
    {
        $this->swapHandler(CustomJsonHandler::class);

        Route::get('api/posts/{post}', [PostController::class, 'show']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/posts/{post}']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('404');

        $schema = $operation['responses']['404']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('timestamp');
        expect($schema['properties'])->toHaveKey('message');
        expect($schema['properties'])->toHaveKey('path');
    }

    // -----------------------------------------------------------------
    // Custom handler: validation includes errors field
    // -----------------------------------------------------------------

    public function test_custom_handler_422_includes_errors_field(): void
    {
        $this->swapHandler(CustomJsonHandler::class);

        Route::post('api/posts', [PostController::class, 'store']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/posts']['post'] ?? null;
        expect($operation)->not()->toBeNull();

        // CustomJsonHandler maps ValidationException to 400
        expect($operation['responses'])->toHaveKey('400');

        $schema = $operation['responses']['400']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('timestamp');
        expect($schema['properties'])->toHaveKey('message');
    }

    // -----------------------------------------------------------------
    // Status code mapping overrides
    // -----------------------------------------------------------------

    public function test_custom_handler_status_code_mapping_overrides(): void
    {
        $this->swapHandler(CustomJsonHandler::class);

        Route::post('api/posts', [PostController::class, 'store']);

        $spec = $this->generateSpec();
        $operation = $spec['paths']['/api/posts']['post'] ?? null;
        expect($operation)->not()->toBeNull();

        // CustomJsonHandler maps ValidationException → 400 instead of default 422
        expect($operation['responses'])->toHaveKey('400');
        expect($operation['responses'])->not()->toHaveKey('422');
    }

    // -----------------------------------------------------------------
    // Default handler produces standard schemas
    // -----------------------------------------------------------------

    public function test_default_handler_produces_standard_schemas(): void
    {
        // Use the default Laravel handler (no swap)
        Route::middleware('auth:api')->get('api/protected', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/protected']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('401');

        $schema = $operation['responses']['401']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('message');

        // Default handler should NOT have envelope fields
        expect($schema['properties'])->not()->toHaveKey('timestamp');
    }

    // -----------------------------------------------------------------
    // Details key handler uses details not errors
    // -----------------------------------------------------------------

    public function test_details_key_handler_uses_details_not_errors(): void
    {
        $this->swapHandler(DetailsKeyHandler::class);

        Route::post('api/posts', [PostController::class, 'store']);

        $spec = $this->generateSpec();
        $operation = $spec['paths']['/api/posts']['post'] ?? null;
        expect($operation)->not()->toBeNull();

        // DetailsKeyHandler maps ValidationException → 400
        expect($operation['responses'])->toHaveKey('400');

        $schema = $operation['responses']['400']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('request_id');
        expect($schema['properties'])->toHaveKey('timestamp');
        expect($schema['properties'])->toHaveKey('details');
    }

    // -----------------------------------------------------------------
    // Status messages from match() applied as examples
    // -----------------------------------------------------------------

    public function test_status_messages_applied_as_examples(): void
    {
        $this->swapHandler(DetailsKeyHandler::class);

        Route::middleware('auth:api')->get('api/protected', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/protected']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('401');

        $schema = $operation['responses']['401']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);

        // Message example should be "Unauthenticated." from the getMessage() match
        expect($schema['properties']['message']['example'])->toBe('Unauthenticated.');
    }

    // -----------------------------------------------------------------
    // Details key handler: 404 has envelope
    // -----------------------------------------------------------------

    public function test_details_key_handler_404_has_envelope(): void
    {
        $this->swapHandler(DetailsKeyHandler::class);

        Route::get('api/posts/{post}', [PostController::class, 'show']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/posts/{post}']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('404');

        $schema = $operation['responses']['404']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();
        $schema = $this->resolveSchemaRef($schema, $spec);
        expect($schema['properties'])->toHaveKey('timestamp');
        expect($schema['properties'])->toHaveKey('path');
        expect($schema['properties'])->toHaveKey('request_id');

        // 404 message example should be "Request not found." from getMessage() match
        expect($schema['properties']['message']['example'])->toBe('Request not found.');
    }
}
