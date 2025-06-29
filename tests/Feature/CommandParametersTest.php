<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SeveralDocsController;
use openapiphp\openapi\Reader;

it('can execute the command with a specific file parameter', function () {
    Route::get('/route-1', [SeveralDocsController::class, 'docOne']);
    Route::get('/route-2', [SeveralDocsController::class, 'docTwo']);

    // Configure multiple doc files
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
    $this->assertFileIsReadable($fileOne);

    // Reset the filesystem to check docTwo exists
    Storage::disk('public')->delete('api-documentation-one.json');

    // Now generate the second file
    $this->artisan('documentation:generate --file=docTwo')
        ->assertExitCode(0);

    $this->assertFileExists($fileTwo);
    $this->assertFileIsReadable($fileTwo);

    // Parse and verify the content
    $parsedFileTwo = Reader::readFromJson(file_get_contents($fileTwo));
    expect($parsedFileTwo->paths)
        ->toHaveCount(1);
});

it('returns error when specifying non-existent file', function () {
    config()->set('api-documentation.ui.storage.files', [
        'docOne' => [
            'name' => 'Doc one',
            'filename' => 'api-documentation-one.json',
            'process' => true,
        ],
    ]);

    $this->artisan('documentation:generate --file=nonExistent')
        ->assertExitCode(1);
});
