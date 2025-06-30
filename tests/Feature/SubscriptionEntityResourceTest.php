<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SubscriptionEntityController;

beforeEach(function () {
    // Register routes for subscription entity testing
    Route::prefix('/subscriptions')->group(function () {
        Route::prefix('/{subscriptionId}')->group(function () {
            Route::prefix('/entities')->group(function () {
                Route::put('/attach', [SubscriptionEntityController::class, 'attach'])
                    ->name('subscription.entities.attach');
                Route::put('/detach', [SubscriptionEntityController::class, 'detach'])
                    ->name('subscription.entities.detach');
            });
        });
        Route::get('/entities/count', [SubscriptionEntityController::class, 'count'])
            ->name('subscriptions.entities.count');
    });
});

function findSubscriptionRoute(array $docs, string $uri, string $method = 'PUT'): ?array
{
    foreach ($docs as $doc) {
        if (isset($doc['method']) && $doc['method'] === $method &&
            isset($doc['uri']) && str_contains($doc['uri'], $uri)) {
            return $doc;
        }
    }

    return null;
}

it('generates proper example for subscription entity attachment', function () {
    // Rule: Project Context - Laravel API Documentation checks all existing routes
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the subscription entity attach endpoint
    $attachRoute = findSubscriptionRoute($documentation, 'subscriptions/{subscriptionId}/entities/attach');
    expect($attachRoute)->not->toBeNull('Subscription entity attach endpoint should be documented');

    // Verify basic route information
    expect($attachRoute)->toHaveKey('uri');
    expect($attachRoute['uri'])->toContain('entities/attach');
    expect($attachRoute)->toHaveKey('responses');

    // Check response structure
    $responses = $attachRoute['responses'];
    expect($responses)->toHaveKey('200');

    $successResponse = $responses['200'];
    expect($successResponse)->toHaveKey('content_type');
    expect($successResponse['content_type'])->toBe('application/json');
    expect($successResponse)->toHaveKey('type');

    // Verify that the response has properties from dynamic analysis
    expect($successResponse)->toHaveKey('properties');

    // Verify that the enhanced analysis flag is set
    expect($successResponse)->toHaveKey('enhanced_analysis');
    expect($successResponse['enhanced_analysis'])->toBeTrue();

    // Verify that the example is generated
    expect($successResponse)->toHaveKey('example');
    expect($successResponse['example'])->not->toBe('(no example available)');

    // Check that the example contains the expected structure
    $example = $successResponse['example'];
    expect($example)->toBeArray();

    // The example should be an array with at least one item
    expect(count($example))->toBeGreaterThan(0);

    // Each item should have id, type, and title
    $firstItem = $example[0];
    expect($firstItem)->toHaveKey('id');
    expect($firstItem)->toHaveKey('type');
    expect($firstItem)->toHaveKey('title');
});

it('includes additional documentation from attributes', function () {
    // Rule: Documentation - Document API interactions and data flows
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the subscription entity attach endpoint
    $attachRoute = findSubscriptionRoute($documentation, 'subscriptions/{subscriptionId}/entities/attach');
    expect($attachRoute)->not->toBeNull('Subscription entity attach endpoint should be documented');

    // Verify that the additional documentation is included
    expect($attachRoute)->toHaveKey('additional_documentation');
    $additionalDocs = $attachRoute['additional_documentation'];

    expect($additionalDocs)->toHaveKey('url');
    expect($additionalDocs['url'])->toBe('https://example.com/docs');

    expect($additionalDocs)->toHaveKey('description');
    expect($additionalDocs['description'])->toBe('External documentation');

    // Verify that the description from the attribute is included
    expect($attachRoute)->toHaveKey('description');
    expect($attachRoute['description'])->toContain('Logs an user in');
    expect($attachRoute['description'])->toContain('This route requires a valid email and password');

    // Verify that the tags are included
    expect($attachRoute)->toHaveKey('tags');
    expect($attachRoute['tags'])->toContain('Test');
    expect($attachRoute['tags'])->toContain('Subscription');
});
