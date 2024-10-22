<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;

it('recognises registered route', function () {
    Route::get('/route-1', function () {
        return 'test';
    });
    Route::get('/route-2', [SimpleController::class, 'index']);

    expect(Route::getRoutes()->count())->toBe(2);
});
