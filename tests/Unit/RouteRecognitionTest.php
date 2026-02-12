<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;

it('recognises registered route', function () {
    Route::get('/route-1', function () {
        return 'test';
    });
    Route::get('/route-2', [SimpleController::class, 'index']);

    $routes = Route::getRoutes();
    dump(collect($routes->getRoutes())->map(fn ($r) => $r->uri() . ' [' . implode('|', $r->methods()) . ']')->all());
    expect($routes->count())->toBe(2);
});
