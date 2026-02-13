<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\ExcludedController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\PartiallyExcludedController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ExcludeFromDocsTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_class_level_exclude_removes_all_routes(): void
    {
        Route::get('api/excluded', [ExcludedController::class, 'index']);
        Route::get('api/excluded/{id}', [ExcludedController::class, 'show']);

        $spec = $this->generateSpec();

        expect($spec['paths'] ?? [])->not()->toHaveKey('/api/excluded');
        expect($spec['paths'] ?? [])->not()->toHaveKey('/api/excluded/{id}');
    }

    public function test_method_level_exclude_removes_only_that_route(): void
    {
        Route::get('api/partial', [PartiallyExcludedController::class, 'index']);
        Route::get('api/partial/secret', [PartiallyExcludedController::class, 'secret']);
        Route::get('api/partial/{id}', [PartiallyExcludedController::class, 'show']);

        $spec = $this->generateSpec();

        expect($spec['paths'])->toHaveKey('/api/partial');
        expect($spec['paths'])->not()->toHaveKey('/api/partial/secret');
        expect($spec['paths'])->toHaveKey('/api/partial/{id}');
    }

    public function test_non_excluded_routes_remain(): void
    {
        Route::get('api/excluded', [ExcludedController::class, 'index']);
        Route::get('api/normal', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec();

        expect($spec['paths'] ?? [])->not()->toHaveKey('/api/excluded');
        expect($spec['paths'])->toHaveKey('/api/normal');
    }
}
