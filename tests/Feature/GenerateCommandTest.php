<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * Tests the api:generate Artisan command using the v2 pipeline.
 */
class GenerateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // -----------------------------------------------------------------
    // 1. Command exits successfully
    // -----------------------------------------------------------------

    public function test_command_executes_successfully(): void
    {
        Route::get('api/posts', [PostController::class, 'index']);

        $this->artisan('api:generate')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------
    // 2. Command generates output file
    // -----------------------------------------------------------------

    public function test_command_generates_output_file(): void
    {
        Route::get('api/posts', [PostController::class, 'index']);

        $this->artisan('api:generate')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('api-documentation.json');
    }

    // -----------------------------------------------------------------
    // 3. Generated spec is valid OpenAPI
    // -----------------------------------------------------------------

    public function test_generated_spec_is_valid_openapi(): void
    {
        Route::apiResource('api/posts', PostController::class);

        $this->artisan('api:generate')
            ->assertExitCode(0);

        $content = Storage::disk('public')->get('api-documentation.json');
        expect($content)->not()->toBeNull();

        $spec = json_decode($content, true);
        expect($spec)->not()->toBeNull();
        expect($spec['openapi'])->toBe('3.1.0');
        expect($spec)->toHaveKey('info');
        expect($spec)->toHaveKey('paths');
        expect($spec['paths'])->not()->toBeEmpty();
    }

    // -----------------------------------------------------------------
    // 4. Command handles empty routes gracefully
    // -----------------------------------------------------------------

    public function test_command_handles_empty_routes_gracefully(): void
    {
        // No routes registered — command should still succeed
        $this->artisan('api:generate')
            ->assertExitCode(0);
    }
}
