<?php

use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DynamicResponseController;

it('can analyze dynamic JsonResource with array_map structure', function () {
    // Rule: Code Style and Structure - Write concise, technical PHP code with accurate examples
    $analyzer = app(ResponseAnalyzer::class);

    $result = $analyzer->analyzeControllerMethod(
        DynamicResponseController::class,
        'attach'
    );

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('type', 'array')
        ->and($result)->toHaveKey('items')
        ->and($result['items'])->toHaveKey('type', 'object')
        ->and($result['items'])->toHaveKey('properties');

    // Check that enhanced analysis flag is set
    expect($result)->toHaveKey('enhanced_analysis', true);

    // Check that example is generated
    expect($result)->toHaveKey('example');
    expect($result['example'])->toBeArray();

    // The example should be an array with at least one item
    $example = $result['example'];
    expect(count($example))->toBeGreaterThanOrEqual(1);

    // Each item in the example should have id, type, and title
    if (count($example) > 0) {
        $firstItem = $example[0];
        expect($firstItem)->toHaveKey('id')
            ->and($firstItem)->toHaveKey('type')
            ->and($firstItem)->toHaveKey('title');
    }
});

it('can detect proper field types from dynamic array structure', function () {
    // Rule: Code Style and Structure - Keep the code clean and readable
    $analyzer = app(ResponseAnalyzer::class);

    $result = $analyzer->analyzeControllerMethod(
        DynamicResponseController::class,
        'attach'
    );

    // Verify field types through the example data
    expect($result)->toHaveKey('example');
    expect($result['example'])->toBeArray();

    if (count($result['example']) > 0) {
        $example = $result['example'][0];

        // Verify that id field is present and is a string
        expect($example)->toHaveKey('id');
        expect($example['id'])->toBeString();

        // Verify that type field is present and is a string
        expect($example)->toHaveKey('type');
        expect($example['type'])->toBeString();

        // Verify that title field is present and is a string
        expect($example)->toHaveKey('title');
        expect($example['title'])->toBeString();
    }
});

it('generates proper OpenAPI schema for dynamic resource responses', function () {
    // Rule: Project Context - Laravel API Documentation is a PHP package that provides the ability to automatically generate API documentation
    $analyzer = app(ResponseAnalyzer::class);

    $result = $analyzer->analyzeControllerMethod(
        DynamicResponseController::class,
        'attach'
    );

    // Should have the basic structure for array of objects schema
    expect($result)->toHaveKey('type', 'array');
    expect($result)->toHaveKey('items');
    expect($result['items'])->toHaveKey('type', 'object');
    expect($result['items'])->toHaveKey('properties');

    // Should have enhanced analysis flag
    expect($result)->toHaveKey('enhanced_analysis', true);

    // Should have example data
    expect($result)->toHaveKey('example');
    expect($result['example'])->toBeArray();

    // Verify example structure if available
    if (count($result['example']) > 0) {
        $example = $result['example'][0];
        expect($example)->toHaveKey('id');
        expect($example)->toHaveKey('type');
        expect($example)->toHaveKey('title');
    }
});
