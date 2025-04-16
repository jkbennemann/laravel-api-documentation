<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SeveralDocsController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use openapiphp\openapi\Reader;
use openapiphp\openapi\spec\Components;

it('can execute the command', function () {
    $this->artisan('documentation:generate');
})->throwsNoExceptions();

it('can generate a simplistic documentation file', function () {
    Route::get('/route-1', [SimpleController::class, 'simple']);

    $this->artisan('documentation:generate')
        ->assertExitCode(0);

    $file = Storage::disk('public')->path('api-documentation.json');

    $this->assertFileExists($file);
    $this->assertFileIsReadable($file);
    $this->assertNotEmpty(file_get_contents($file));

    $parsedFile = Reader::readFromJson(file_get_contents($file));

    expect($parsedFile->openapi)
        ->toBe('3.0.2')
        ->and($parsedFile->info->title)
        ->toBe('Service API Documentation')
        ->and($parsedFile->info->version)
        ->toBe('1.0.0')
        ->and($parsedFile->paths)
        ->toHaveCount(1)
        ->and($parsedFile->components)
        ->toBeInstanceOf(Components::class);
});

it('can generate a simplistic documentation file with old file config', function () {
    Route::get('/route-1', [SimpleController::class, 'simple']);
    config()->set('api-documentation.ui.storage.files', []);
    config()->set('api-documentation.ui.storage.filename', 'api-documentation.json');

    $this->artisan('documentation:generate')
        ->assertExitCode(0);

    $file = Storage::disk('public')->path('api-documentation.json');

    $parsedFile = Reader::readFromJson(file_get_contents($file));

    expect($parsedFile->openapi)
        ->toBe('3.0.2')
        ->and($parsedFile->info->title)
        ->toBe('Service API Documentation')
        ->and($parsedFile->info->version)
        ->toBe('1.0.0')
        ->and($parsedFile->paths)
        ->toHaveCount(1)
        ->and($parsedFile->components)
        ->toBeInstanceOf(Components::class);
});

it('can generate several simplistic documentation files', function () {
    Route::get('/route-1', [SeveralDocsController::class, 'docOne']);
    Route::get('/route-2', [SeveralDocsController::class, 'docTwo']);
    Route::get('/route-3', [SeveralDocsController::class, 'bothDocs']);
    Route::get('/route-4', [SeveralDocsController::class, 'defaultDoc']);
    config()->set('api-documentation.ui.storage.files', [
        'docOne' => [
            'name' => 'Doc one',
            'filename' => 'api-documentation-one.json',
            'process' => true
        ],
        'docTwo' => [
            'name' => 'Doc two',
            'filename' => 'api-documentation-two.json',
            'process' => true
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

    expect($parsedFileOne->openapi)
        ->toBe('3.0.2')
        ->and($parsedFileOne->info->title)
        ->toBe('Service API Documentation')
        ->and($parsedFileOne->info->version)
        ->toBe('1.0.0')
        ->and($parsedFileOne->paths)
        ->toHaveCount(3)
        ->and($parsedFileOne->components)
        ->toBeInstanceOf(Components::class)
        ->and($parsedFileTwo->openapi)
        ->toBe('3.0.2')
        ->and($parsedFileTwo->info->title)
        ->toBe('Service API Documentation')
        ->and($parsedFileTwo->info->version)
        ->toBe('1.0.0')
        ->and($parsedFileTwo->paths)
        ->toHaveCount(2)
        ->and($parsedFileTwo->components)
        ->toBeInstanceOf(Components::class);

});

it('can generate a simplistic documentation file with a different title', function () {
    config()->set('api-documentation.title', 'Test');

    $this->artisan('documentation:generate')
        ->assertExitCode(0);

    $file = Storage::disk('public')->path('api-documentation.json');

    $parsedFile = Reader::readFromJson(file_get_contents($file));

    expect($parsedFile->info->title)
        ->toBe('Test');
});

it('can generate a simplistic documentation file with a different version', function () {
    config()->set('api-documentation.version', '0.0.1');

    $this->artisan('documentation:generate')
        ->assertExitCode(0);

    $file = Storage::disk('public')->path('api-documentation.json');

    $parsedFile = Reader::readFromJson(file_get_contents($file));

    expect($parsedFile->info->version)
        ->toBe('0.0.1');
});
