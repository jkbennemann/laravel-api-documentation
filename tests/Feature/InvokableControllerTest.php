<?php

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\InvokableController;

it('can process invokable controllers', function () {
    // Register an invokable controller route
    Route::get('/status-invokable', InvokableController::class);

    $routeComposition = app(RouteComposition::class);
    $routes = $routeComposition->process('default');

    // Find our specific route by URI (Laravel stores URIs without leading slash)
    $statusRoute = collect($routes)->firstWhere('uri', 'status-invokable');

    expect($statusRoute)->not->toBeNull('Route status-invokable should exist in processed routes');
    expect($statusRoute['method'])->toBe('GET');
    expect($statusRoute['uri'])->toBe('status-invokable');
    expect($statusRoute['action']['controller'])->toBe(InvokableController::class);
    expect($statusRoute['action']['method'])->toBe('__invoke');
});

it('generates correct documentation for invokable controllers', function () {
    // Register an invokable controller route
    Route::get('/health-invokable', InvokableController::class);

    $routeComposition = app(RouteComposition::class);
    $routes = $routeComposition->process('default');

    // Find our specific route by URI (Laravel stores URIs without leading slash)
    $healthRoute = collect($routes)->firstWhere('uri', 'health-invokable');

    expect($healthRoute)->not->toBeNull();

    // Check basic route information
    expect($healthRoute['method'])->toBe('GET');
    expect($healthRoute['uri'])->toBe('health-invokable');
    expect($healthRoute['action']['controller'])->toBe(InvokableController::class);
    expect($healthRoute['action']['method'])->toBe('__invoke');

    // Check that attributes are processed correctly
    expect($healthRoute['tags'])->toContain('Invokable');
    expect($healthRoute['summary'])->toBe('Get application status');
    expect($healthRoute['description'])->toBe('Returns the current application status and health information');
});

it('can mix traditional and invokable controllers', function () {
    // Mix traditional and invokable controller routes
    Route::get('/status-final', InvokableController::class);
    Route::get('/users-final', 'JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController@simple');

    $routeComposition = app(RouteComposition::class);
    $routes = $routeComposition->process('default');

    // Find our specific routes by URI (Laravel stores URIs without leading slash)
    $invokableRoute = collect($routes)->firstWhere('uri', 'status-final');
    $traditionalRoute = collect($routes)->firstWhere('uri', 'users-final');

    expect($invokableRoute)->not->toBeNull();
    expect($traditionalRoute)->not->toBeNull();

    // Check invokable controller route
    expect($invokableRoute['action']['method'])->toBe('__invoke');
    expect($invokableRoute['action']['controller'])->toBe(InvokableController::class);

    // Check traditional controller route
    expect($traditionalRoute['action']['method'])->toBe('simple');
    expect($traditionalRoute['action']['controller'])->toBe('JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController');
});
