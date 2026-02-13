<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\CollectionReturnTypeController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DataResponseCollectionController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\ExplicitNonCollectionController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\RepositoryPaginatedController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class PaginationDetectionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    private function resolveSchema(array $spec, array $schema): array
    {
        if (isset($schema['$ref'])) {
            $name = str_replace('#/components/schemas/', '', $schema['$ref']);

            return $spec['components']['schemas'][$name] ?? $schema;
        }

        return $schema;
    }

    public function test_collection_auto_detected_from_static_call(): void
    {
        Route::get('api/items', [DataResponseCollectionController::class, 'index']);

        $spec = $this->generateSpec();
        $schema = $spec['paths']['/api/items']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

        expect($schema)->not()->toBeNull();
        $resolved = $this->resolveSchema($spec, $schema);

        expect($resolved['type'] ?? null)->toBe('object');
        expect($resolved['properties'] ?? [])->toHaveKey('data');

        // The 'data' property should be an array (collection), not a single object
        $dataSchema = $resolved['properties']['data'];
        expect($dataSchema['type'] ?? null)->toBe('array');
        expect($dataSchema)->toHaveKey('items');
    }

    public function test_pagination_detected_from_method_name(): void
    {
        Route::get('api/paginated', [RepositoryPaginatedController::class, 'index']);

        $spec = $this->generateSpec();
        $schema = $spec['paths']['/api/paginated']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

        expect($schema)->not()->toBeNull();
        $resolved = $this->resolveSchema($spec, $schema);

        // Should have pagination metadata
        expect($resolved['properties'] ?? [])->toHaveKey('links');
        expect($resolved['properties'] ?? [])->toHaveKey('meta');
        expect($resolved['properties'] ?? [])->toHaveKey('data');
    }

    public function test_pagination_wraps_resource_envelope(): void
    {
        Route::get('api/collection', [CollectionReturnTypeController::class, 'index']);

        $spec = $this->generateSpec();
        $schema = $spec['paths']['/api/collection']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

        expect($schema)->not()->toBeNull();
        $resolved = $this->resolveSchema($spec, $schema);

        // Should have data as array plus links and meta
        expect($resolved['type'] ?? null)->toBe('object');
        expect($resolved['properties'] ?? [])->toHaveKey('data');
        expect($resolved['properties'] ?? [])->toHaveKey('links');
        expect($resolved['properties'] ?? [])->toHaveKey('meta');

        // Data should be an array
        $dataSchema = $resolved['properties']['data'];
        expect($dataSchema['type'] ?? null)->toBe('array');
    }

    public function test_single_resource_stays_single_without_collection_call(): void
    {
        Route::get('api/single', [ExplicitNonCollectionController::class, 'show']);

        $spec = $this->generateSpec();
        $schema = $spec['paths']['/api/single']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

        expect($schema)->not()->toBeNull();
        $resolved = $this->resolveSchema($spec, $schema);

        // Should be an object with 'data' property
        expect($resolved['type'] ?? null)->toBe('object');
        expect($resolved['properties'] ?? [])->toHaveKey('data');

        // The 'data' property should NOT be an array (no ::collection() call in code)
        $dataSchema = $this->resolveSchema($spec, $resolved['properties']['data']);
        expect($dataSchema['type'] ?? null)->not()->toBe('array');
    }

    public function test_already_wrapped_schema_not_double_wrapped(): void
    {
        Route::get('api/items', [DataResponseCollectionController::class, 'index']);

        $spec = $this->generateSpec();
        $schema = $spec['paths']['/api/items']['get']['responses']['200']['content']['application/json']['schema'] ?? null;

        expect($schema)->not()->toBeNull();
        $resolved = $this->resolveSchema($spec, $schema);

        // If links/meta are present, they should only appear once (no nesting)
        if (isset($resolved['properties']['links'])) {
            // links should be a direct object with pagination properties, not nested
            expect($resolved['properties']['links']['type'] ?? null)->toBe('object');
            expect($resolved['properties']['links']['properties'] ?? [])->toHaveKey('first');
        }

        if (isset($resolved['properties']['meta'])) {
            expect($resolved['properties']['meta']['type'] ?? null)->toBe('object');
        }

        // data should not contain another data key (no double-wrapping)
        $dataSchema = $resolved['properties']['data'] ?? [];
        if (($dataSchema['type'] ?? '') === 'array' && isset($dataSchema['items'])) {
            $itemSchema = $this->resolveSchema($spec, $dataSchema['items']);
            // Items should describe the resource properties, not another pagination wrapper
            expect($itemSchema)->not()->toHaveKey('links');
            expect($itemSchema)->not()->toHaveKey('meta');
        }
    }
}
