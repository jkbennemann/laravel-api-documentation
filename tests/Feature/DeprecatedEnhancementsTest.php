<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DeprecatedClassController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\DeprecatedController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class DeprecatedEnhancementsTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_method_level_deprecated_marks_operation(): void
    {
        Route::get('api/legacy', [DeprecatedController::class, 'legacyStatus']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/legacy']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['deprecated'])->toBeTrue();
    }

    public function test_class_level_deprecated_marks_all_operations(): void
    {
        Route::get('api/old', [DeprecatedClassController::class, 'index']);
        Route::get('api/old/{id}', [DeprecatedClassController::class, 'show']);

        $spec = $this->generateSpec();

        $indexOp = $spec['paths']['/api/old']['get'] ?? null;
        $showOp = $spec['paths']['/api/old/{id}']['get'] ?? null;

        expect($indexOp)->not()->toBeNull();
        expect($indexOp['deprecated'])->toBeTrue();

        expect($showOp)->not()->toBeNull();
        expect($showOp['deprecated'])->toBeTrue();
    }

    public function test_not_deprecated_exempts_from_class_deprecation(): void
    {
        Route::get('api/old', [DeprecatedClassController::class, 'index']);
        Route::get('api/old/health', [DeprecatedClassController::class, 'health']);

        $spec = $this->generateSpec();

        $indexOp = $spec['paths']['/api/old']['get'] ?? null;
        $healthOp = $spec['paths']['/api/old/health']['get'] ?? null;

        expect($indexOp['deprecated'])->toBeTrue();
        expect($healthOp)->not()->toHaveKey('deprecated');
    }

    public function test_deprecation_message_in_description(): void
    {
        Route::get('api/legacy', [DeprecatedController::class, 'legacyStatus']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/legacy']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['description'] ?? '')->toContain('**Deprecated:**');
        expect($operation['description'] ?? '')->toContain('Use v2/status instead');
    }

    public function test_class_deprecation_message_in_description(): void
    {
        Route::get('api/old', [DeprecatedClassController::class, 'index']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/old']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['description'] ?? '')->toContain('**Deprecated:**');
        expect($operation['description'] ?? '')->toContain('Use V2 API instead');
    }
}
