<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DtoResponseController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\SimpleAnnotated;

it('can create an instance of the service', function () {
    expect(app(RouteComposition::class))
        ->toBeInstanceOf(RouteComposition::class);
});

it('can generate route information for simplistic route', function () {
    Route::get('route-1', [SimpleController::class, 'simple']);

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
            'query_parameters',
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
        ->toHaveCount(1)
        ->toHaveKeys([200]);
});

it('can generate route information for route with a tag', function () {
    Route::get('route-1', [SimpleController::class, 'tag']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
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
        ->toHaveCount(17)
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
        ->toHaveCount(17)
        ->and($routeData[0]['tags'])
        ->toBeArray()
        ->toHaveCount(2)
        ->and($routeData[0]['tags'][0])
        ->toBe('My-Tag')
        ->and($routeData[0]['tags'][1])
        ->toBe('Another-Tag');
});

it('can generate route information for route with a description', function () {
    Route::get('route-1', [SimpleController::class, 'description']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['description'])
        ->toBe('My Description');
});

it('can generate route information for route with a summary', function () {
    Route::get('route-1', [SimpleController::class, 'summary']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['summary'])
        ->toBe('My Summary');
});

it('can generate route information for route with a middleware', function () {
    Route::middleware('auth:web')->get('route-1', [SimpleController::class, 'summary']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['middlewares'])
        ->toBeArray()
        ->toHaveCount(1)
        ->and($routeData[0]['middlewares'][0])
        ->toBe('auth:web');
});

it('can generate route information for route with a required path parameter', function () {
    Route::get('route/{id}', [SimpleController::class, 'parameter']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['request_parameters'])
        ->toBeArray()
        ->toHaveCount(1)
        ->and($routeData[0]['request_parameters']['id'])
        ->toHaveKeys([
            'description',
            'required',
            'type',
            'format',
        ])
        ->and($routeData[0]['request_parameters']['id']['required'])
        ->toBeTrue()
        ->and($routeData[0]['request_parameters']['id']['type'])
        ->toBe('integer')
        ->and($routeData[0]['request_parameters']['id']['format'])
        ->toBeNull();
});

it('can generate route information for route with a optional path parameter', function () {
    Route::get('route/{?id}', [SimpleController::class, 'optionalParameter']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['request_parameters'])
        ->toBeArray()
        ->toHaveCount(1)
        ->and($routeData[0]['request_parameters']['id'])
        ->toHaveKeys([
            'description',
            'required',
            'type',
            'format',
        ])
        ->and($routeData[0]['request_parameters']['id']['required'])
        ->toBeFalse();
});

it('can generate route information for route with multiple path parameters', function () {
    Route::get('route/{firstParam}/{?secondParam}', [SimpleController::class, 'multiParameter']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['request_parameters'])
        ->toBeArray()
        ->toHaveCount(2)
        ->and($routeData[0]['request_parameters']['paramOne'])
        ->toHaveKeys([
            'description',
            'required',
            'type',
            'format',
            'example',
        ])
        ->and($routeData[0]['request_parameters']['paramOne']['required'])
        ->toBeTrue()
        ->and($routeData[0]['request_parameters']['paramTwo']['required'])
        ->toBeFalse()
        ->and($routeData[0]['request_parameters']['paramOne']['type'])
        ->toBe('integer')
        ->and($routeData[0]['request_parameters']['paramOne']['format'])
        ->toBeNull()
        ->and($routeData[0]['request_parameters']['paramTwo']['type'])
        ->toBe('string')
        ->and($routeData[0]['request_parameters']['paramTwo']['format'])
        ->toBe('uuid');
});

it('can generate route information for route with an email example', function () {
    Route::get('route/{mail}', [SimpleController::class, 'mailExampleParameter']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['request_parameters'])
        ->toBeArray()
        ->toHaveCount(1)
        ->and($routeData[0]['request_parameters']['mail'])
        ->toHaveKeys([
            'description',
            'required',
            'type',
            'format',
            'example',
        ])
        ->and($routeData[0]['request_parameters']['mail']['required'])
        ->toBeTrue()
        ->and($routeData[0]['request_parameters']['mail']['type'])
        ->toBe('string')
        ->and($routeData[0]['request_parameters']['mail']['format'])
        ->toBe('email')
        ->and($routeData[0]['request_parameters']['mail']['example'])
        ->toBeArray()
        ->toHaveCount(3)
        ->and($routeData[0]['request_parameters']['mail']['example']['type'])
        ->toBe('string')
        ->and($routeData[0]['request_parameters']['mail']['example']['format'])
        ->toBe('email')
        ->and($routeData[0]['request_parameters']['mail']['example']['value'])
        ->toBe('mail@test.com');
});

it('can generate route information for route with a data resource', function () {
    Route::get('route-1', [SimpleController::class, 'simpleResource']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['responses'])
        ->toBeArray()
        ->toHaveCount(1)
        ->and($routeData[0]['responses'])
        ->toHaveKeys([200]);

    // Rule: Error Handling - Implement proper error boundaries
    expect($routeData[0]['responses'][200])
        ->toHaveKeys([
            'description',
            'type',
            'properties',
            'headers',
        ]);

    // Enhanced analysis may or may not be present depending on the response type
    if (isset($routeData[0]['responses'][200]['enhanced_analysis'])) {
        expect($routeData[0]['responses'][200]['enhanced_analysis'])->toBeTrue();
    }

    expect($routeData[0]['responses'][200]['type'])
        ->toBe('object');

    expect($routeData[0]['responses'][200]['description'])
        ->toBe('A sample description')
        ->and($routeData[0]['responses'][200]['headers'])
        ->toBeArray()
        ->toHaveKeys(['X-Header'])
        ->and($routeData[0]['responses'][200]['headers']['X-Header'])
        ->toBe('Some header');
});

it('can generate route information for route with a spatie dto resource', function () {
    Route::get('route-1', [DtoResponseController::class, 'simple']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->toHaveCount(17)
        ->and($routeData[0]['responses'])
        ->toBeArray()
        ->toHaveCount(1)
        ->and($routeData[0]['responses'])
        ->toHaveKeys([200]);

    expect($routeData[0]['responses'][200])
        ->toHaveKeys([
            'description',
            'resource',
            'type',
            'headers',
        ])
        ->and($routeData[0]['responses'][200]['resource'])
        ->toBe(SimpleAnnotated::class)
        ->and($routeData[0]['responses'][200]['type'])
        ->toBe('object')
        ->and($routeData[0]['responses'][200]['description'])
        ->toBe('A sample description')
        ->and($routeData[0]['responses'][200]['headers'])
        ->toBeEmpty();
});
