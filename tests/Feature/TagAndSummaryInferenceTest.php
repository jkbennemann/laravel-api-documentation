<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\CreateBoxController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\GetBoxController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\ListBoxesController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\RegisterController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class TagAndSummaryInferenceTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_invokable_controller_strips_action_prefix_for_tag(): void
    {
        Route::get('api/boxes', ListBoxesController::class);
        Route::get('api/boxes/{boxId}', GetBoxController::class);
        Route::post('api/boxes', CreateBoxController::class);

        $spec = $this->generateSpec();

        $listOp = $spec['paths']['/api/boxes']['get'] ?? null;
        $getOp = $spec['paths']['/api/boxes/{boxId}']['get'] ?? null;
        $createOp = $spec['paths']['/api/boxes']['post'] ?? null;

        // GetBox and CreateBox should produce the same tag
        expect($getOp['tags'][0])->toBe($createOp['tags'][0]);

        // Tags should not include action prefixes
        expect($listOp['tags'][0])->not()->toContain('List');
        expect($getOp['tags'][0])->not()->toContain('Get');
        expect($createOp['tags'][0])->not()->toContain('Create');

        // Tags should contain "Box" (the resource name)
        expect($listOp['tags'][0])->toContain('Box');
        expect($getOp['tags'][0])->toBe('Box');
    }

    public function test_resource_controller_summary_includes_resource_name(): void
    {
        Route::apiResource('api/posts', PostController::class);

        $spec = $this->generateSpec();

        $indexOp = $spec['paths']['/api/posts']['get'] ?? null;
        $showOp = $spec['paths']['/api/posts/{post}']['get'] ?? null;
        $storeOp = $spec['paths']['/api/posts']['post'] ?? null;
        $updateOp = $spec['paths']['/api/posts/{post}']['put'] ?? null;
        $destroyOp = $spec['paths']['/api/posts/{post}']['delete'] ?? null;

        expect($indexOp['summary'])->toContain('Post');
        expect($showOp['summary'])->toContain('Post');
        expect($storeOp['summary'])->toContain('Post');
        expect($updateOp['summary'])->toContain('Post');
        expect($destroyOp['summary'])->toContain('Post');
    }

    public function test_confirmed_rule_adds_confirmation_field(): void
    {
        Route::post('api/register', [RegisterController::class, 'store']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/register']['post'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('requestBody');

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        // Should have password_confirmation field
        expect($schema['properties'])->toHaveKey('password_confirmation');

        // password_confirmation should be required since password is required
        expect($schema['required'])->toContain('password_confirmation');
    }

    public function test_route_with_path_parameter_gets_404_response(): void
    {
        Route::get('api/boxes/{boxId}', GetBoxController::class);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/boxes/{boxId}']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->toHaveKey('404');
    }

    public function test_route_without_path_parameters_has_no_404(): void
    {
        Route::get('api/health', [ListBoxesController::class, '__invoke']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/health']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['responses'])->not()->toHaveKey('404');
    }
}
