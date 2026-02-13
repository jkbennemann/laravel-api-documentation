<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Controllers\AuthController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Controllers\BoxController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * End-to-end test mirroring the real API Gateway documentation workflow.
 *
 * Exercises: #[DocumentationFile], #[DataResponse], #[Parameter] on Resources,
 * #[Tag], #[Summary], FormRequest rules, error auto-detection, and response headers.
 */
class ApiGatewayDocumentationTest extends TestCase
{
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        // Register routes like the real public-api.php
        Route::prefix('v1')->group(function () {
            Route::post('login', [AuthController::class, 'login']);

            Route::middleware('auth:api')->group(function () {
                Route::get('boxes/{boxId}', [BoxController::class, 'show']);
                Route::post('boxes', [BoxController::class, 'store']);
                Route::delete('boxes/{boxId}', [BoxController::class, 'destroy']);
            });
        });

        app(SchemaRegistry::class)->reset();

        // Run full v2 pipeline with DocumentationFile filter
        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover('public-api');

        $emitter = app(OpenApiEmitter::class);
        $this->spec = $emitter->emit($contexts, config('api-documentation'));
    }

    // -----------------------------------------------------------------
    // 1. Spec structure
    // -----------------------------------------------------------------

    public function test_spec_is_valid_openapi_3_1(): void
    {
        expect($this->spec)
            ->toHaveKey('openapi')
            ->toHaveKey('info')
            ->toHaveKey('paths');

        expect($this->spec['openapi'])->toBe('3.1.0');
        expect($this->spec['info'])->toHaveKey('title');
        expect($this->spec['info'])->toHaveKey('version');
    }

    // -----------------------------------------------------------------
    // 2. DocumentationFile filtering
    // -----------------------------------------------------------------

    public function test_only_public_api_routes_included(): void
    {
        $paths = array_keys($this->spec['paths']);

        expect($paths)->toContain('/v1/login');
        expect($paths)->toContain('/v1/boxes/{boxId}');
        expect($paths)->toContain('/v1/boxes');
    }

    // -----------------------------------------------------------------
    // 3. Login request body from FormRequest rules
    // -----------------------------------------------------------------

    public function test_login_endpoint_has_request_body_from_form_request(): void
    {
        $operation = $this->spec['paths']['/v1/login']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSpecSchema($schema);

        expect($resolved['properties'])->toHaveKey('email');
        expect($resolved['properties'])->toHaveKey('password');
    }

    // -----------------------------------------------------------------
    // 4. Login response has schema from inline resource
    // -----------------------------------------------------------------

    public function test_login_response_has_resource_schema(): void
    {
        $operation = $this->spec['paths']['/v1/login']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('200');

        $response200 = $operation['responses']['200'];
        expect($response200)->toHaveKey('content');

        $schema = $response200['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSpecSchema($schema);

        expect($resolved['properties'])->toHaveKey('access_token');
        expect($resolved['properties'])->toHaveKey('token_type');
        expect($resolved['properties'])->toHaveKey('expires_in');
    }

    // -----------------------------------------------------------------
    // 5. Login response has headers
    // -----------------------------------------------------------------

    public function test_login_response_has_headers(): void
    {
        $operation = $this->spec['paths']['/v1/login']['post'] ?? null;
        expect($operation)->not()->toBeNull();

        $response200 = $operation['responses']['200'];
        expect($response200)->toHaveKey('headers');
        expect($response200['headers'])->toHaveKey('access_token');
    }

    // -----------------------------------------------------------------
    // 6. Box show has path parameter
    // -----------------------------------------------------------------

    public function test_box_show_has_path_parameter(): void
    {
        $operation = $this->spec['paths']['/v1/boxes/{boxId}']['get'] ?? null;
        expect($operation)->not()->toBeNull();

        $parameters = $operation['parameters'] ?? [];
        $boxIdParam = collect($parameters)->firstWhere('name', 'boxId');
        expect($boxIdParam)->not()->toBeNull();
        expect($boxIdParam['in'])->toBe('path');
        expect($boxIdParam['required'])->toBeTrue();
    }

    // -----------------------------------------------------------------
    // 7. Box show response uses BoxResource schema via #[Parameter]
    // -----------------------------------------------------------------

    public function test_box_show_response_uses_resource_schema(): void
    {
        $operation = $this->spec['paths']['/v1/boxes/{boxId}']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('200');

        $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSpecSchema($schema);

        // BoxResource is a JsonResource so the response is wrapped in 'data'
        $properties = $resolved['properties'] ?? [];
        if (isset($properties['data'])) {
            $inner = $this->resolveSpecSchema($properties['data']);
            $properties = $inner['properties'] ?? [];
        }

        expect($properties)->toHaveKey('id');
        expect($properties)->toHaveKey('title');
        expect($properties)->toHaveKey('domain');
    }

    // -----------------------------------------------------------------
    // 8. Box store has 202 status code
    // -----------------------------------------------------------------

    public function test_box_store_has_202_status(): void
    {
        $operation = $this->spec['paths']['/v1/boxes']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('202');

        $response202 = $operation['responses']['202'];
        expect($response202)->toHaveKey('content');

        $schema = $response202['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSpecSchema($schema);

        expect($resolved['properties'])->toHaveKey('id');
        expect($resolved['properties'])->toHaveKey('status');
        expect($resolved['properties'])->toHaveKey('message');
    }

    // -----------------------------------------------------------------
    // 9. Box destroy has 204 no content
    // -----------------------------------------------------------------

    public function test_box_destroy_has_204_response(): void
    {
        $operation = $this->spec['paths']['/v1/boxes/{boxId}']['delete'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('204');
    }

    // -----------------------------------------------------------------
    // 10. Auth routes have 401 error response
    // -----------------------------------------------------------------

    public function test_auth_routes_have_401_error_response(): void
    {
        // Box show is behind auth:api middleware
        $operation = $this->spec['paths']['/v1/boxes/{boxId}']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('401');

        // Login is NOT behind auth:api
        $loginOp = $this->spec['paths']['/v1/login']['post'] ?? null;
        expect($loginOp)->not()->toBeNull();
        expect($loginOp['responses'])->not()->toHaveKey('401');
    }

    // -----------------------------------------------------------------
    // 11. Tags extracted from attributes
    // -----------------------------------------------------------------

    public function test_tags_extracted_from_attributes(): void
    {
        $operation = $this->spec['paths']['/v1/boxes/{boxId}']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['tags'])->toContain('Box');

        $loginOp = $this->spec['paths']['/v1/login']['post'] ?? null;
        expect($loginOp)->not()->toBeNull();
        expect($loginOp['tags'])->toContain('Authentication');

        // Top-level tags array in spec
        expect($this->spec)->toHaveKey('tags');
        $tagNames = array_column($this->spec['tags'], 'name');
        expect($tagNames)->toContain('Box');
        expect($tagNames)->toContain('Authentication');
    }

    // -----------------------------------------------------------------
    // 12. Components contain resource schemas
    // -----------------------------------------------------------------

    public function test_components_contain_resource_schemas(): void
    {
        $components = $this->spec['components'] ?? [];

        expect($components)->not()->toBeEmpty();
        expect($components)->toHaveKey('schemas');

        // BoxResource should be registered as a component
        $schemaNames = array_keys($components['schemas']);
        $hasBoxSchema = collect($schemaNames)->contains(fn ($name) => str_contains($name, 'Box'));
        expect($hasBoxSchema)->toBeTrue();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function resolveSpecSchema(array $schema): array
    {
        if (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            $name = str_replace('#/components/schemas/', '', $ref);

            return $this->spec['components']['schemas'][$name] ?? $schema;
        }

        return $schema;
    }
}
