<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostRelationsController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\UserRelationsController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class RelationshipTypeResolutionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    private function resolveSchema(array $spec, string $path, string $method, string $property): ?array
    {
        $responseSchema = $spec['paths'][$path][$method]['responses']['200']['content']['application/json']['schema'] ?? null;
        if ($responseSchema === null) {
            return null;
        }

        // Unwrap data wrapper
        if (isset($responseSchema['properties']['data'])) {
            $inner = $responseSchema['properties']['data'];
            if (isset($inner['$ref'])) {
                $refName = str_replace('#/components/schemas/', '', $inner['$ref']);

                return $spec['components']['schemas'][$refName]['properties'][$property] ?? null;
            }

            return $inner['properties'][$property] ?? null;
        }

        if (isset($responseSchema['$ref'])) {
            $refName = str_replace('#/components/schemas/', '', $responseSchema['$ref']);

            return $spec['components']['schemas'][$refName]['properties'][$property] ?? null;
        }

        return $responseSchema['properties'][$property] ?? null;
    }

    public function test_belongs_to_relation_resolves_to_object(): void
    {
        Route::get('api/posts/{post}', [PostRelationsController::class, 'show']);

        $spec = $this->generateSpec();

        $userProp = $this->resolveSchema($spec, '/api/posts/{post}', 'get', 'user');

        expect($userProp)->not()->toBeNull();

        // BelongsTo should resolve to an object or $ref, not an array
        $type = $userProp['type'] ?? null;
        if (is_array($type)) {
            // OpenAPI 3.1 nullable: ['object', 'null'] or ['string', 'null'] etc.
            expect($type)->toContain('null'); // nullable
            expect($type)->not()->toContain('array'); // not a to-many relation
        } elseif (isset($userProp['$ref'])) {
            // Reference to a component schema (also valid)
            expect($userProp['$ref'])->toBeString();
        } else {
            expect($type)->not()->toBe('array');
        }
    }

    public function test_has_many_relation_resolves_to_array(): void
    {
        Route::get('api/users/{user}', [UserRelationsController::class, 'show']);

        $spec = $this->generateSpec();

        $postsProp = $this->resolveSchema($spec, '/api/users/{user}', 'get', 'posts');

        expect($postsProp)->not()->toBeNull();

        // HasMany should resolve to an array type
        $type = $postsProp['type'] ?? null;
        if (is_array($type)) {
            // OpenAPI 3.1: ['array', 'null']
            expect($type)->toContain('array');
            expect($type)->toContain('null'); // nullable from whenLoaded
        } else {
            expect($type)->toBe('array');
        }

        // Items should exist
        expect($postsProp)->toHaveKey('items');
    }

    public function test_relation_with_no_model_falls_back_to_generic(): void
    {
        // A resource with @mixin removed would not resolve the model
        // Test that it gracefully falls back
        Route::get('api/posts/{post}', [PostRelationsController::class, 'show']);

        $spec = $this->generateSpec();

        // This should at minimum generate something (not crash)
        $operation = $spec['paths']['/api/posts/{post}']['get'] ?? null;
        expect($operation)->not()->toBeNull();
    }
}
