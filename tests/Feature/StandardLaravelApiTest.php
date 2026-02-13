<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\StatusController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * End-to-end test verifying the full v2 pipeline against standard Laravel API patterns.
 *
 * Route registration → RouteDiscovery → AnalysisPipeline → OpenApiEmitter
 *
 * All stubs are vanilla Laravel code (no attributes) to verify zero-config works.
 */
class StandardLaravelApiTest extends TestCase
{
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        // Register routes like a standard Laravel app would after `php artisan install:api`
        Route::prefix('api')->group(function () {
            Route::apiResource('posts', PostController::class);
            Route::get('status', StatusController::class);
        });

        // Reset the schema registry to avoid state leakage between tests
        app(SchemaRegistry::class)->reset();

        // Run the full v2 pipeline
        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);
        $this->spec = $emitter->emit($contexts, config('api-documentation'));
    }

    // -----------------------------------------------------------------
    // 1. Spec structure
    // -----------------------------------------------------------------

    public function test_spec_has_correct_openapi_version_and_structure(): void
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
    // 2. All CRUD paths present
    // -----------------------------------------------------------------

    public function test_all_crud_and_status_paths_are_present(): void
    {
        $paths = array_keys($this->spec['paths']);

        expect($paths)->toContain('/api/posts');
        expect($paths)->toContain('/api/posts/{post}');
        expect($paths)->toContain('/api/status');
    }

    // -----------------------------------------------------------------
    // 3. GET /api/posts — paginated collection
    // -----------------------------------------------------------------

    public function test_get_posts_index_has_200_response(): void
    {
        $operation = $this->spec['paths']['/api/posts']['get'] ?? null;

        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('200');

        $response200 = $operation['responses']['200'];
        expect($response200)->toHaveKey('content');
        expect($response200['content'])->toHaveKey('application/json');

        // Should have a schema describing the response
        $schema = $response200['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        // Should have a pagination envelope with data as array, links, and meta
        $resolved = $this->resolveSpecSchema($schema);
        expect($resolved['type'] ?? null)->toBe('object');
        expect($resolved['properties'] ?? [])->toHaveKey('data');
        expect($resolved['properties'] ?? [])->toHaveKey('links');
        expect($resolved['properties'] ?? [])->toHaveKey('meta');

        // data should be an array of items
        $dataSchema = $resolved['properties']['data'];
        expect($dataSchema['type'] ?? null)->toBe('array');
        expect($dataSchema)->toHaveKey('items');
    }

    // -----------------------------------------------------------------
    // 4. POST /api/posts — requestBody from StorePostRequest rules
    // -----------------------------------------------------------------

    public function test_post_store_has_request_body_with_validation_rules(): void
    {
        $operation = $this->spec['paths']['/api/posts']['post'] ?? null;

        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $requestBody = $operation['requestBody'];
        expect($requestBody['content'])->toHaveKey('application/json');

        $schema = $requestBody['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        // Resolve schema — may be inline or $ref
        $resolved = $this->resolveSpecSchema($schema);

        expect($resolved['properties'])->toHaveKey('title');
        expect($resolved['properties'])->toHaveKey('content');
        expect($resolved['properties'])->toHaveKey('status');

        // 'title' and 'content' are required
        expect($resolved['required'] ?? [])->toContain('title');
        expect($resolved['required'] ?? [])->toContain('content');
    }

    // -----------------------------------------------------------------
    // 5. GET /api/posts/{post} — path parameter + response
    // -----------------------------------------------------------------

    public function test_get_posts_show_has_path_parameter_and_response(): void
    {
        $operation = $this->spec['paths']['/api/posts/{post}']['get'] ?? null;

        expect($operation)->not()->toBeNull();

        // Should have path parameter 'post'
        $parameters = $operation['parameters'] ?? [];
        $postParam = collect($parameters)->firstWhere('name', 'post');
        expect($postParam)->not()->toBeNull();
        expect($postParam['in'])->toBe('path');
        expect($postParam['required'])->toBeTrue();

        // Should have 200 response
        expect($operation['responses'])->toHaveKey('200');
    }

    // -----------------------------------------------------------------
    // 6. PUT /api/posts/{post} — requestBody from UpdatePostRequest
    // -----------------------------------------------------------------

    public function test_put_posts_update_has_request_body(): void
    {
        $operation = $this->spec['paths']['/api/posts/{post}']['put'] ?? null;

        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $requestBody = $operation['requestBody'];
        expect($requestBody['content'])->toHaveKey('application/json');

        $schema = $requestBody['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSpecSchema($schema);

        expect($resolved['properties'])->toHaveKey('title');
        expect($resolved['properties'])->toHaveKey('content');
        expect($resolved['properties'])->toHaveKey('status');
    }

    // -----------------------------------------------------------------
    // 7. DELETE /api/posts/{post} — 204 no content
    // -----------------------------------------------------------------

    public function test_delete_posts_destroy_has_204_response(): void
    {
        $operation = $this->spec['paths']['/api/posts/{post}']['delete'] ?? null;

        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('204');
    }

    // -----------------------------------------------------------------
    // 8. GET /api/status — inline JSON response
    // -----------------------------------------------------------------

    public function test_get_status_has_200_response(): void
    {
        $operation = $this->spec['paths']['/api/status']['get'] ?? null;

        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('200');

        $response200 = $operation['responses']['200'];
        expect($response200)->toHaveKey('content');
        expect($response200['content'])->toHaveKey('application/json');

        $schema = $response200['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSpecSchema($schema);

        // Should detect the inline JSON properties
        expect($resolved['properties'] ?? [])->toHaveKey('status');
        expect($resolved['properties'] ?? [])->toHaveKey('version');
    }

    // -----------------------------------------------------------------
    // 9. PostResource properties in response schema
    // -----------------------------------------------------------------

    public function test_post_resource_schema_has_correct_properties(): void
    {
        // Get the show response schema (returns PostResource directly)
        $operation = $this->spec['paths']['/api/posts/{post}']['get'] ?? null;
        expect($operation)->not()->toBeNull();

        $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        $resolved = $this->resolveSpecSchema($schema);

        // PostResource maps: id, title, content, status, published_at, created_at, user
        // The data may be wrapped in a 'data' key (Laravel default JsonResource $wrap)
        $properties = $resolved['properties'] ?? [];
        if (isset($properties['data'])) {
            $inner = $this->resolveSpecSchema($properties['data']);
            $properties = $inner['properties'] ?? [];
        }

        expect($properties)->toHaveKey('id');
        expect($properties)->toHaveKey('title');
        expect($properties)->toHaveKey('content');
    }

    // -----------------------------------------------------------------
    // 10. Components populated
    // -----------------------------------------------------------------

    public function test_components_schemas_are_populated(): void
    {
        $components = $this->spec['components'] ?? [];

        expect($components)->not()->toBeEmpty();
        expect($components)->toHaveKey('schemas');
        expect($components['schemas'])->not()->toBeEmpty();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Resolve a spec-level schema that may be a $ref to a component.
     */
    private function resolveSpecSchema(array $schema): array
    {
        if (isset($schema['$ref'])) {
            // Extract component name from $ref: "#/components/schemas/PostResource" -> "PostResource"
            $ref = $schema['$ref'];
            $name = str_replace('#/components/schemas/', '', $ref);

            return $this->spec['components']['schemas'][$name] ?? $schema;
        }

        return $schema;
    }
}
