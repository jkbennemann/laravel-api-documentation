<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use openapiphp\openapi\Reader;

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
        ->toBe('Laravel API Documentation')
        ->and($parsedFile->info->version)
        ->toBe('1.0.0')
        ->and($parsedFile->paths)
        ->toHaveCount(1)
        ->and($parsedFile->components)
        ->toBeNull();
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
