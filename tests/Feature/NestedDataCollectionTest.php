<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DtoController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\NestedData;

it('generates correct OpenAPI schema for NestedData with DataCollectionOf', function () {
    Route::get('nested-data', [DtoController::class, 'nestedSimple']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0])
        ->toBeArray()
        ->and($routeData[0]['responses'][200])
        ->toBeArray()
        ->and($routeData[0]['responses'][200]['resource'])
        ->toBe(NestedData::class);

    // Verify that NestedData is properly analyzed with DataCollectionOf
    $analyzer = app(\JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer::class);
    $schema = $analyzer->analyzeSpatieDataObject(NestedData::class);

    // Check the items property has correct DataCollectionOf structure
    expect($schema['properties']['items'])
        ->toBeArray()
        ->and($schema['properties']['items']['type'])
        ->toBe('array')
        ->and($schema['properties']['items']['items'])
        ->toBeArray()
        ->and($schema['properties']['items']['items']['type'])
        ->toBe('object');

    // Verify nested Data object properties are correctly mapped
    expect($schema['properties']['items']['items']['properties'])
        ->toHaveKey('id')
        ->toHaveKey('age')
        ->toHaveKey('is_active'); // snake_case from isActive due to SnakeCaseMapper

    // Verify property types
    expect($schema['properties']['items']['items']['properties']['id']['type'])
        ->toBe('string')
        ->and($schema['properties']['items']['items']['properties']['age']['type'])
        ->toBe('integer')
        ->and($schema['properties']['items']['items']['properties']['is_active']['type'])
        ->toBe('boolean');
});

it('handles nested data collections in route processing', function () {
    Route::get('nested-collection', [DtoController::class, 'nestedSimple']);

    $service = app(RouteComposition::class);
    $routeData = $service->process();

    // Verify the route is processed correctly
    expect($routeData)
        ->toHaveCount(1)
        ->and($routeData[0]['uri'])
        ->toBe('nested-collection')
        ->and($routeData[0]['method'])
        ->toBe('GET')
        ->and($routeData[0]['responses'])
        ->toBeArray()
        ->toHaveKey(200);

    // Verify response structure
    expect($routeData[0]['responses'][200])
        ->toBeArray()
        ->and($routeData[0]['responses'][200]['type'])
        ->toBe('object')
        ->and($routeData[0]['responses'][200]['resource'])
        ->toBe(NestedData::class);
});
