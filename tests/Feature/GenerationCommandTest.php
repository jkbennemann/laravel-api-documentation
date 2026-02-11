<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SeveralDocsController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SmartController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;
use openapiphp\openapi\Reader;
use openapiphp\openapi\spec\Components;

class GenerationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear the storage disk before each test
        Storage::disk('public')->deleteDirectory('');
        Storage::disk('public')->makeDirectory('');
    }

    /** @test */
    public function it_can_execute_the_command()
    {
        $this->artisan('documentation:generate')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_can_generate_a_simplistic_documentation_file()
    {
        Route::get('/route-1', [SimpleController::class, 'simple']);

        $this->artisan('documentation:generate')
            ->assertExitCode(0);

        $file = Storage::disk('public')->path('api-documentation.json');

        $this->assertFileExists($file);
        $this->assertFileIsReadable($file);
        $this->assertNotEmpty(file_get_contents($file));

        $parsedFile = Reader::readFromJson(file_get_contents($file));

        $this->assertEquals('3.0.2', $parsedFile->openapi);
        $this->assertEquals('Service API Documentation', $parsedFile->info->title);
        $this->assertEquals('1.0.0', $parsedFile->info->version);
        $this->assertCount(1, $parsedFile->paths);
        $this->assertInstanceOf(Components::class, $parsedFile->components);
    }

    /** @test */
    public function it_can_generate_a_simplistic_documentation_file_with_old_file_config()
    {
        Route::get('/route-1', [SimpleController::class, 'simple']);
        config()->set('api-documentation.ui.storage.files', []);
        config()->set('api-documentation.ui.storage.filename', 'api-documentation.json');

        $this->artisan('documentation:generate')
            ->assertExitCode(0);

        $file = Storage::disk('public')->path('api-documentation.json');

        $parsedFile = Reader::readFromJson(file_get_contents($file));

        $this->assertEquals('3.0.2', $parsedFile->openapi);
        $this->assertEquals('Service API Documentation', $parsedFile->info->title);
        $this->assertEquals('1.0.0', $parsedFile->info->version);
        $this->assertCount(1, $parsedFile->paths);
        $this->assertInstanceOf(Components::class, $parsedFile->components);
    }

    /** @test */
    public function it_can_generate_several_simplistic_documentation_files_with_authentication()
    {
        Route::get('/route-1', [SeveralDocsController::class, 'docOne'])->middleware('auth');
        Route::get('/route-2', [SeveralDocsController::class, 'docTwo']);
        Route::get('/route-3', [SeveralDocsController::class, 'bothDocs']);
        Route::get('/route-4', [SeveralDocsController::class, 'defaultDoc']);

        config()->set('api-documentation.ui.storage.files', [
            'docOne' => [
                'name' => 'Doc one',
                'filename' => 'api-documentation-one.json',
                'process' => true,
            ],
            'docTwo' => [
                'name' => 'Doc two',
                'filename' => 'api-documentation-two.json',
                'process' => true,
            ],
            'docThree' => [
                'name' => 'Doc Three',
                'filename' => 'api-documentation-three.json',
            ],
        ]);
        config()->set('api-documentation.ui.storage.default_file', 'docOne');

        $this->artisan('documentation:generate')
            ->assertExitCode(0);

        $fileOne = Storage::disk('public')->path('api-documentation-one.json');
        $fileTwo = Storage::disk('public')->path('api-documentation-two.json');

        $parsedFileOne = Reader::readFromJson(file_get_contents($fileOne));
        $parsedFileTwo = Reader::readFromJson(file_get_contents($fileTwo));

        // Test first doc file
        $this->assertEquals('3.0.2', $parsedFileOne->openapi);
        $this->assertEquals('Service API Documentation', $parsedFileOne->info->title);
        $this->assertEquals('1.0.0', $parsedFileOne->info->version);
        $this->assertCount(3, $parsedFileOne->paths);
        $this->assertInstanceOf(Components::class, $parsedFileOne->components);

        // Test second doc file
        $this->assertEquals('3.0.2', $parsedFileTwo->openapi);
        $this->assertEquals('Service API Documentation', $parsedFileTwo->info->title);
        $this->assertEquals('1.0.0', $parsedFileTwo->info->version);
        $this->assertCount(2, $parsedFileTwo->paths);
        $this->assertInstanceOf(Components::class, $parsedFileTwo->components);
    }

    /** @test */
    public function it_can_generate_a_documentation_file_with_a_different_title()
    {
        config()->set('api-documentation.title', 'Test');

        // Verify the OpenApi service reads updated config via get()
        $openApiService = app(\JkBennemann\LaravelApiDocumentation\Services\OpenApi::class);
        $spec = $openApiService->get();
        $this->assertEquals('Test', $spec->info->title, 'OpenApi service should reflect updated config');

        // Build the documentation directly to verify the full flow
        $builder = app(\JkBennemann\LaravelApiDocumentation\Services\DocumentationBuilder::class);
        iterator_to_array($builder->build('api-documentation.json'));

        $file = Storage::disk('public')->path('api-documentation.json');

        $this->assertFileExists($file);
        $parsedFile = Reader::readFromJson(file_get_contents($file));

        $this->assertEquals('Test', $parsedFile->info->title);
    }

    /** @test */
    public function it_can_generate_a_documentation_file_with_a_different_version()
    {
        config()->set('api-documentation.version', '0.0.1');

        $this->artisan('documentation:generate')
            ->assertExitCode(0);

        $file = Storage::disk('public')->path('api-documentation.json');

        $parsedFile = Reader::readFromJson(file_get_contents($file));

        $this->assertEquals('0.0.1', $parsedFile->info->version);
    }

    /** @test */
    public function it_can_generate_documentation_for_a_specific_file_only()
    {
        Route::get('/route-1', [SeveralDocsController::class, 'docOne']);
        Route::get('/route-2', [SeveralDocsController::class, 'docTwo']);

        config()->set('api-documentation.ui.storage.files', [
            'docOne' => [
                'name' => 'Doc one',
                'filename' => 'api-documentation-one.json',
                'process' => true,
            ],
            'docTwo' => [
                'name' => 'Doc two',
                'filename' => 'api-documentation-two.json',
                'process' => true,
            ],
        ]);

        // Execute command with specific file parameter
        $this->artisan('documentation:generate --file=docOne')
            ->assertExitCode(0);

        // Only the specified file should exist
        $fileOne = Storage::disk('public')->path('api-documentation-one.json');
        $fileTwo = Storage::disk('public')->path('api-documentation-two.json');

        $this->assertFileExists($fileOne);
        $this->assertFileDoesNotExist($fileTwo);

        // Parse and verify the content
        $parsedFileOne = Reader::readFromJson(file_get_contents($fileOne));
        $this->assertCount(1, $parsedFileOne->paths);
        $this->assertArrayHasKey('/route-1', $parsedFileOne->paths);
    }

    /** @test */
    public function it_returns_error_when_specifying_non_existent_file()
    {
        config()->set('api-documentation.ui.storage.files', [
            'docOne' => [
                'name' => 'Doc one',
                'filename' => 'api-documentation-one.json',
                'process' => true,
            ],
        ]);

        $this->artisan('documentation:generate --file=nonExistent')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_can_generate_documentation_with_smart_responses()
    {
        Route::get('/smart-controller', [SmartController::class, 'index']);

        config()->set('api-documentation.smart_responses.enabled', true);

        $this->artisan('documentation:generate')
            ->assertExitCode(0);

        $file = Storage::disk('public')->path('api-documentation.json');
        $parsedFile = Reader::readFromJson(file_get_contents($file));

        $this->assertArrayHasKey('/smart-controller', $parsedFile->paths);
        $this->assertNotNull($parsedFile->paths['/smart-controller']->get->responses[200]);
    }

    /** @test */
    public function it_handles_missing_filename_in_config()
    {
        Route::get('/route-1', [SimpleController::class, 'simple']);

        config()->set('api-documentation.ui.storage.files', [
            'docOne' => [
                'name' => 'Doc one',
                'process' => true,
                // Note: No filename
            ],
        ]);

        $this->artisan('documentation:generate')
            ->expectsOutput('Generating documentation...')
            ->expectsOutput('Generating default documentation...')
            ->expectsOutput('Using filename: api-documentation.json')
            ->assertExitCode(0);

        // Should fall back to default file
        $file = Storage::disk('public')->path('api-documentation.json');
        $this->assertFileExists($file);
    }

    /** @test */
    public function test_fallback_logic_works_with_no_files_config()
    {
        Route::get('/route-1', [SimpleController::class, 'simple']);

        // Clear any existing files config completely to trigger fallback
        config()->set('api-documentation.ui.storage.files', []);

        $exitCode = Artisan::call('documentation:generate');
        $this->assertEquals(0, $exitCode);

        // Should fall back to default file
        $file = Storage::disk('public')->path('api-documentation.json');
        $this->assertFileExists($file);
    }
}
