<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PolicyController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class PolicyIntrospectionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('api-documentation.routes.prefixes', ['api']);
        config()->set('api-documentation.routes.middleware', []);
    }

    public function test_can_middleware_triggers_403_with_ability_name(): void
    {
        Route::put('api/posts/{post}', [PolicyController::class, 'update'])
            ->middleware('can:update,post');

        $spec = $this->generateSpec();
        $responses = $spec['paths']['/api/posts/{post}']['put']['responses'] ?? [];

        expect($responses)->toHaveKey('403');
        expect($responses['403']['description'])->toContain('update');
    }

    public function test_authorize_call_in_ast_triggers_403_with_ability(): void
    {
        Route::put('api/posts/{post}', [PolicyController::class, 'update']);

        $spec = $this->generateSpec();
        $responses = $spec['paths']['/api/posts/{post}']['put']['responses'] ?? [];

        expect($responses)->toHaveKey('403');
        expect($responses['403']['description'])->toContain('update');
    }

    public function test_no_authorization_no_403(): void
    {
        Route::get('api/posts/{post}', [PolicyController::class, 'view']);

        $spec = $this->generateSpec();
        $responses = $spec['paths']['/api/posts/{post}']['get']['responses'] ?? [];

        expect($responses)->not()->toHaveKey('403');
    }

    public function test_multiple_abilities_listed_in_description(): void
    {
        // can: middleware with one ability + AST authorize with another
        Route::delete('api/posts/{post}', [PolicyController::class, 'delete'])
            ->middleware('can:manage,post');

        $spec = $this->generateSpec();
        $responses = $spec['paths']['/api/posts/{post}']['delete']['responses'] ?? [];

        expect($responses)->toHaveKey('403');
        // Should list both manage (from middleware) and delete (from AST)
        expect($responses['403']['description'])->toContain('manage');
        expect($responses['403']['description'])->toContain('delete');
    }

    public function test_403_schema_has_message_property(): void
    {
        Route::put('api/posts/{post}', [PolicyController::class, 'update'])
            ->middleware('can:update,post');

        $spec = $this->generateSpec();
        $schema = $spec['paths']['/api/posts/{post}']['put']['responses']['403']['content']['application/json']['schema'] ?? null;

        expect($schema)->not()->toBeNull();
        expect($schema['properties'])->toHaveKey('message');
    }
}
