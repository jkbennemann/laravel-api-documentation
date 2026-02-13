<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\DeprecatedController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class DeprecatedDetectionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_phpdoc_deprecated_marks_operation(): void
    {
        Route::get('api/legacy-status', [DeprecatedController::class, 'legacyStatus']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/legacy-status']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['deprecated'])->toBeTrue();
    }

    public function test_non_deprecated_has_no_flag(): void
    {
        Route::get('api/status', [DeprecatedController::class, 'currentStatus']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/status']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->not()->toHaveKey('deprecated');
    }

    public function test_deprecated_and_non_deprecated_coexist(): void
    {
        Route::get('api/legacy-status', [DeprecatedController::class, 'legacyStatus']);
        Route::get('api/status', [DeprecatedController::class, 'currentStatus']);

        $spec = $this->generateSpec();

        $legacyOp = $spec['paths']['/api/legacy-status']['get'] ?? null;
        $currentOp = $spec['paths']['/api/status']['get'] ?? null;

        expect($legacyOp['deprecated'])->toBeTrue();
        expect($currentOp)->not()->toHaveKey('deprecated');
    }
}
