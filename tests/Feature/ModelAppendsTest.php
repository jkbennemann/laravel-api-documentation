<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\EloquentModelAnalyzer;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\UserAppendsController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ModelAppendsTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_get_appends_returns_appended_fields(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        $appends = $analyzer->getAppends(User::class);
        expect($appends)->toContain('display_name');
    }

    public function test_appended_property_type_resolved_from_accessor(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        $schema = $analyzer->getPropertyType(User::class, 'display_name');
        expect($schema)->not()->toBeNull();
        expect($schema->type)->toBe('string');
    }

    public function test_appended_field_appears_in_spec(): void
    {
        Route::get('api/users/{user}', [UserAppendsController::class, 'show']);

        $spec = $this->generateSpec();

        // Resolve the schema (could be inline or ref)
        $responseSchema = $spec['paths']['/api/users/{user}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;
        expect($responseSchema)->not()->toBeNull();

        // Unwrap data wrapper if present
        $schema = $responseSchema;
        if (isset($schema['properties']['data'])) {
            $inner = $schema['properties']['data'];
            if (isset($inner['$ref'])) {
                $refName = str_replace('#/components/schemas/', '', $inner['$ref']);
                $schema = $spec['components']['schemas'][$refName] ?? null;
            } else {
                $schema = $inner;
            }
        } elseif (isset($schema['$ref'])) {
            $refName = str_replace('#/components/schemas/', '', $schema['$ref']);
            $schema = $spec['components']['schemas'][$refName] ?? null;
        }

        expect($schema)->not()->toBeNull();
        expect($schema['properties'])->toHaveKey('display_name');
        expect($schema['properties']['display_name']['type'])->toBe('string');
    }

    public function test_model_without_appends_returns_empty(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        // Post model doesn't have $appends
        $appends = $analyzer->getAppends(\JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Post::class);
        expect($appends)->toBeEmpty();
    }
}
