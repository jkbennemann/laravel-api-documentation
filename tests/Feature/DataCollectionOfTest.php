<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\NestedData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\UserData;

it('can analyze DataCollectionOf attribute in Spatie Data objects', function () {
    $analyzer = app(ResponseAnalyzer::class);

    // Analyze the NestedData class which has DataCollectionOf(Data::class) on the items property
    $schema = $analyzer->analyzeSpatieDataObject(NestedData::class);

    expect($schema)
        ->toBeArray()
        ->and($schema['type'])
        ->toBe('object')
        ->and($schema['properties'])
        ->toBeArray()
        ->toHaveKey('id')
        ->toHaveKey('age')
        ->toHaveKey('items');

    // Check that the items property is correctly identified as an array
    expect($schema['properties']['items'])
        ->toBeArray()
        ->and($schema['properties']['items']['type'])
        ->toBe('array');

    // Check that the items array has the correct item schema from DataCollectionOf(Data::class)
    expect($schema['properties']['items']['items'])
        ->toBeArray()
        ->and($schema['properties']['items']['items']['type'])
        ->toBe('object')
        ->and($schema['properties']['items']['items']['properties'])
        ->toBeArray()
        ->toHaveKey('id')
        ->toHaveKey('age')
        ->toHaveKey('is_active'); // snake_case mapped from isActive

    // Verify the nested Data object structure
    expect($schema['properties']['items']['items']['properties']['id'])
        ->toBeArray()
        ->and($schema['properties']['items']['items']['properties']['id']['type'])
        ->toBe('string')
        ->and($schema['properties']['items']['items']['properties']['age'])
        ->toBeArray()
        ->and($schema['properties']['items']['items']['properties']['age']['type'])
        ->toBe('integer')
        ->and($schema['properties']['items']['items']['properties']['is_active'])
        ->toBeArray()
        ->and($schema['properties']['items']['items']['properties']['is_active']['type'])
        ->toBe('boolean');
});

it('handles collections without DataCollectionOf attribute', function () {
    // Use the existing UserData class which has no DataCollectionOf attributes
    $analyzer = app(ResponseAnalyzer::class);
    $schema = $analyzer->analyzeSpatieDataObject(UserData::class);

    expect($schema)
        ->toBeArray()
        ->and($schema['type'])
        ->toBe('object')
        ->and($schema['properties'])
        ->toBeArray();

    // UserData doesn't have collection properties, so this test verifies basic functionality
    expect($schema['properties'])
        ->toHaveKey('id')
        ->toHaveKey('name')
        ->toHaveKey('email');
});
