<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\RequestParameterController;

it('can compose basic request parameters from a request class', function () {
    Route::get('route-1', [RequestParameterController::class, 'simple']);

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
        ->and($routeData[0]['query_parameters'])
        ->toBeArray()
        ->toHaveCount(2)
        ->and($routeData[0]['query_parameters'])
        ->toHaveKeys(['parameter_1', 'parameter_2'])
        ->and($routeData[0]['query_parameters']['parameter_1'])
        ->toHaveKeys([
            'name',
            'description',
            'type',
            'format',
            'required',
            'deprecated',
            'parameters',
        ])
        ->and($routeData[0]['query_parameters']['parameter_1']['name'])
        ->toBe('parameter_1')
        ->and($routeData[0]['query_parameters']['parameter_1']['description'])
        ->toBeString()
        ->toContain('Required')
        ->and($routeData[0]['query_parameters']['parameter_1']['type'])
        ->toBe('string')
        ->and($routeData[0]['query_parameters']['parameter_1']['format'])
        ->toBeNull()
        ->and($routeData[0]['query_parameters']['parameter_1']['required'])
        ->toBeTrue()
        ->and($routeData[0]['query_parameters']['parameter_1']['deprecated'])
        ->toBeFalse()
        ->and($routeData[0]['query_parameters']['parameter_2'])
        ->toHaveKeys([
            'name',
            'description',
            'type',
            'format',
            'required',
            'deprecated',
            'parameters',
        ])
        ->and($routeData[0]['query_parameters']['parameter_2']['name'])
        ->toBe('parameter_2')
        ->and($routeData[0]['query_parameters']['parameter_2']['description'])
        ->toBeString()
        ->toContain('Must be a valid email')
        ->and($routeData[0]['query_parameters']['parameter_2']['type'])
        ->toBe('string')
        ->and($routeData[0]['query_parameters']['parameter_2']['format'])
        ->toBe('email')
        ->and($routeData[0]['query_parameters']['parameter_2']['required'])
        ->toBeFalse()
        ->and($routeData[0]['query_parameters']['parameter_2']['deprecated'])
        ->toBeFalse();
});

it('can compose basic attribute parameters from a request class', function () {
    Route::get('route-1', [RequestParameterController::class, 'stringParameter']);

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
        ->and($routeData[0]['query_parameters'])
        ->toBeArray()
        ->toHaveCount(2)
        ->and($routeData[0]['query_parameters'])
        ->toHaveKeys(['parameter_1', 'parameter_2'])
        ->and($routeData[0]['query_parameters']['parameter_1'])
        ->toHaveKeys([
            'name',
            'description',
            'type',
            'format',
            'required',
            'deprecated',
        ])
        ->and($routeData[0]['query_parameters']['parameter_1']['name'])
        ->toBe('parameter_1')
        ->and($routeData[0]['query_parameters']['parameter_1']['description'])
        ->toBe('The first parameter')
        ->and($routeData[0]['query_parameters']['parameter_1']['type'])
        ->toBe('string')
        ->and($routeData[0]['query_parameters']['parameter_1']['format'])
        ->toBeNull()
        ->and($routeData[0]['query_parameters']['parameter_1']['required'])
        ->toBeTrue()
        ->and($routeData[0]['query_parameters']['parameter_1']['deprecated'])
        ->toBeFalse()
        ->and($routeData[0]['query_parameters']['parameter_2'])
        ->toHaveKeys([
            'name',
            'description',
            'type',
            'format',
            'required',
            'deprecated',
        ])
        ->and($routeData[0]['query_parameters']['parameter_2']['name'])
        ->toBe('parameter_2')
        ->and($routeData[0]['query_parameters']['parameter_2']['description'])
        ->toBe('The second parameter')
        ->and($routeData[0]['query_parameters']['parameter_2']['type'])
        ->toBe('string')
        ->and($routeData[0]['query_parameters']['parameter_2']['format'])
        ->toBe('email')
        ->and($routeData[0]['query_parameters']['parameter_2']['required'])
        ->toBeFalse()
        ->and($routeData[0]['query_parameters']['parameter_2']['deprecated'])
        ->toBeFalse();
});

it('can compose nested attribute parameters from a request class', function () {
    Route::get('route-1', [RequestParameterController::class, 'simpleNestedParameters']);

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
        ->and($routeData[0]['query_parameters'])
        ->toBeArray()
        ->toHaveCount(1)
        ->and($routeData[0]['query_parameters'])
        ->toHaveKeys(['base'])
        ->and($routeData[0]['query_parameters']['base'])
        ->toBeArray()
        ->toHaveCount(7)
        ->and($routeData[0]['query_parameters']['base'])
        ->toHaveKeys([
            'name',
            'description',
            'type',
            'format',
            'required',
            'deprecated',
            'properties',
        ])
        ->and($routeData[0]['query_parameters']['base']['name'])
        ->toBe('base')
        ->and($routeData[0]['query_parameters']['base']['description'])
        ->toBeString()
        ->toContain('Required')
        ->and($routeData[0]['query_parameters']['base']['type'])
        ->toBeIn(['array', 'object'])
        ->and($routeData[0]['query_parameters']['base']['format'])
        ->toBeNull()
        ->and($routeData[0]['query_parameters']['base']['required'])
        ->toBeTrue()
        ->and($routeData[0]['query_parameters']['base']['deprecated'])
        ->toBeFalse()
        ->and($routeData[0]['query_parameters']['base']['properties'])
        ->toBeArray()
        ->toHaveCount(2)
        ->and($routeData[0]['query_parameters']['base']['properties'])
        ->toHaveKeys(['parameter_1', 'parameter_2'])
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_1'])
        ->toHaveKeys([
            'name',
            'description',
            'type',
            'format',
            'required',
            'deprecated',
        ])
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_1']['name'])
        ->toBe('parameter_1')
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_1']['description'])
        ->toBeString()
        ->toContain('Required')
        ->toContain('string')
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_1']['type'])
        ->toBe('string')
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_1']['format'])
        ->toBeNull()
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_1']['required'])
        ->toBeTrue()
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_1']['deprecated'])
        ->toBeFalse()
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_2'])
        ->toHaveKeys([
            'name',
            'description',
            'type',
            'format',
            'required',
            'deprecated',
        ])
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_2']['name'])
        ->toBe('parameter_2')
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_2']['description'])
        ->toBeString()
        ->toContain('email')
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_2']['type'])
        ->toBe('string')
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_2']['format'])
        ->toBe('email')
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_2']['required'])
        ->toBeFalse()
        ->and($routeData[0]['query_parameters']['base']['properties']['parameter_2']['deprecated'])
        ->toBeFalse();
});
