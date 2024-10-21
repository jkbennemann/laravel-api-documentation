<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;

it('can create an instance of the service', function () {
    expect(app(RouteComposition::class))
        ->toBeInstanceOf(RouteComposition::class);
});

it('can generate route information for simplistic route', function () {
    Route::get('route-1', [SimpleController::class, 'simple']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    ray($routeData);

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toHaveKeys([
            'uri',
            'method',
            'summary',
            'description',
            'middlewares',
            'is_vendor',
            'request_parameters',
            'parameters',
            'tags',
            'documentation',
            'responses',
        ])
        ->and($routeData[0]['uri'])
        ->toBe('route-1')
        ->and($routeData[0]['method'])
        ->toBe('GET')
        ->and($routeData[0]['summary'])
        ->toBeNull()
        ->and($routeData[0]['description'])
        ->toBeNull()
        ->and($routeData[0]['middlewares'])
        ->toBeArray()
        ->toBeEmpty()
        ->and($routeData[0]['is_vendor'])
        ->toBeFalse()
        ->and($routeData[0]['request_parameters'])
        ->toBeArray()
        ->toBeEmpty()
        ->and($routeData[0]['parameters'])
        ->toBeArray()
        ->toBeEmpty()
        ->and($routeData[0]['tags'])
        ->toBeArray()
        ->toBeEmpty()
        ->and($routeData[0]['documentation'])
        ->toBeNull()
        ->and($routeData[0]['responses'])
        ->toBeArray()
        ->toHaveCount(0);
});

it('can generate route information for route wit a tag', function () {
    Route::get('route-1', [SimpleController::class, 'tag']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(11)
        ->and($routeData[0]['tags'])
        ->toBeArray()
        ->toHaveCount(1)
        ->and($routeData[0]['tags'][0])
        ->toBe('My-Tag');
});

it('can generate route information for route with multiple tags', function () {
    Route::get('route-1', [SimpleController::class, 'tags']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(11)
        ->and($routeData[0]['tags'])
        ->toBeArray()
        ->toHaveCount(2)
        ->and($routeData[0]['tags'][0])
        ->toBe('My-Tag')
        ->and($routeData[0]['tags'][1])
        ->toBe('Another-Tag');
});

it('can generate route information for route with multiple tags as string', function () {
    Route::get('route-1', [SimpleController::class, 'tags']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(11)
        ->and($routeData[0]['tags'])
        ->toBeArray()
        ->toHaveCount(2)
        ->and($routeData[0]['tags'][0])
        ->toBe('My-Tag')
        ->and($routeData[0]['tags'][1])
        ->toBe('Another-Tag');
});
