<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\StatusController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ScopeDetectionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_passport_scope_middleware_extracts_scopes(): void
    {
        Route::get('api/users', [StatusController::class, 'index'])
            ->middleware(['auth:api', 'scope:read-users,write-users']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('security');
        expect($operation['security'])->toBe([['bearerAuth' => ['read-users', 'write-users']]]);
    }

    public function test_passport_scopes_middleware_extracts_scopes(): void
    {
        Route::get('api/users', [StatusController::class, 'index'])
            ->middleware(['auth:api', 'scopes:admin']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation['security'])->toBe([['bearerAuth' => ['admin']]]);
    }

    public function test_sanctum_ability_middleware_extracts_scopes(): void
    {
        Route::get('api/users', [StatusController::class, 'index'])
            ->middleware(['auth:sanctum', 'ability:create-post,delete-post']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation['security'])->toBe([['bearerAuth' => ['create-post', 'delete-post']]]);
    }

    public function test_sanctum_abilities_middleware_extracts_scopes(): void
    {
        Route::get('api/users', [StatusController::class, 'index'])
            ->middleware(['auth:sanctum', 'abilities:manage-users']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation['security'])->toBe([['bearerAuth' => ['manage-users']]]);
    }

    public function test_auth_without_scopes_has_empty_scopes(): void
    {
        Route::get('api/users', [StatusController::class, 'index'])
            ->middleware(['auth:sanctum']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation['security'])->toBe([['bearerAuth' => []]]);
    }

    public function test_no_auth_middleware_has_no_security(): void
    {
        Route::get('api/users', [StatusController::class, 'index']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation)->not()->toHaveKey('security');
    }
}
