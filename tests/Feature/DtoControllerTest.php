<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DtoController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\NestedData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\DataResource;

it('can generate route information for plain response dto class', function () {
    Route::post('route-1', [DtoController::class, 'nestedSimple']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

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
        ->toBe('POST')
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
        ->toHaveCount(1)
        ->toHaveKeys([200])
        ->and($routeData[0]['responses'][200])
        ->toBeArray()
        ->and($routeData[0]['responses'][200]['headers'])
        ->toBeEmpty()
        ->and($routeData[0]['responses'][200]['description'])
        ->toBeEmpty()
        ->and($routeData[0]['responses'][200]['type'])
        ->toBe('object')
        ->and($routeData[0]['responses'][200]['resource'])
        ->toBe(NestedData::class)
        ->and($routeData[0]['responses'][200]['content_type'])
        ->toBe('application/json');
});

it('can generate route information for route with a nested dto resource', function () {
    Route::get('route-2', [DtoController::class, 'nestedResource']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(12)
        ->and($routeData[0]['tags'])
        ->toBeArray()
        ->and($routeData[0]['responses'][200])
        ->toBeArray()
        ->and($routeData[0]['responses'][200]['resource'])
        ->toBe(DataResource::class);
});

it('can generate route information for route with a nested dto resource collection', function () {
    Route::get('route-2', [DtoController::class, 'nestedResourceCollection']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(12)
        ->and($routeData[0]['tags'])
        ->toBeArray()
        ->and($routeData[0]['responses'][200])
        ->toBeArray()
        ->and($routeData[0]['responses'][200]['type'])
        ->toBe('array')
        ->and($routeData[0]['responses'][200]['resource'])
        ->toBe(DataResource::class);
});

it('can generate route information for route with a plain json response containing a dto', function () {
    Route::get('route-2', [DtoController::class, 'nestedJsonData']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(12)
        ->and($routeData[0]['tags'])
        ->toBeArray()
        ->toHaveCount(0)
        ->and($routeData[0]['responses'][200])
        ->toBeArray()
        ->and($routeData[0]['responses'][200]['resource'])
        ->toBe(DataResource::class);
});
