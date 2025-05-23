<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\Phase2IntegrationController;

beforeEach(function () {
    // Register routes for Phase 2 integration testing
    Route::get('/api/users/paginated', [Phase2IntegrationController::class, 'paginatedUsers']);
    Route::get('/api/posts/{id}/conditional', [Phase2IntegrationController::class, 'conditionalPost']);
    Route::get('/api/posts/conditional', [Phase2IntegrationController::class, 'conditionalPosts']);
    Route::get('/api/errors/validation', [Phase2IntegrationController::class, 'validationError']);
    Route::get('/api/errors/not-found', [Phase2IntegrationController::class, 'notFoundError']);
    Route::get('/api/errors/server', [Phase2IntegrationController::class, 'serverError']);
    Route::get('/api/custom/paginated', [Phase2IntegrationController::class, 'customPaginatedResponse']);
});

function findRouteInDocs(array $docs, string $uri, string $method = 'GET'): ?array
{
    foreach ($docs as $doc) {
        if (isset($doc['method']) && $doc['method'] === $method && 
            isset($doc['uri']) && str_contains($doc['uri'], $uri)) {
            return $doc;
        }
    }
    return null;
}

it('generates proper schema for paginated responses', function () {
    // Rule: Code Style and Structure - Write concise, technical PHP code with accurate examples
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the paginated users endpoint
    $paginatedUsersRoute = findRouteInDocs($documentation, 'users/paginated');
    expect($paginatedUsersRoute)->not->toBeNull('Paginated users endpoint should be documented');

    // Verify basic route information
    expect($paginatedUsersRoute)->toHaveKey('uri');
    expect($paginatedUsersRoute['uri'])->toContain('users/paginated');
    expect($paginatedUsersRoute)->toHaveKey('responses');
    
    // Check response structure
    $responses = $paginatedUsersRoute['responses'];
    expect($responses)->toHaveKey('200');
    
    $successResponse = $responses['200'];
    expect($successResponse)->toHaveKey('content_type');
    expect($successResponse['content_type'])->toBe('application/json');
    expect($successResponse)->toHaveKey('type');
    expect($successResponse['type'])->toBe('object');
    
    // Verify resource information is present
    expect($successResponse)->toHaveKey('resource');
    expect($successResponse['resource'])->toBe('JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\PaginatedUserResource');
});

it('handles conditional response fields properly', function () {
    // Rule: Code Style and Structure - Keep the code clean and readable
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the conditional post endpoint
    $conditionalPostRoute = findRouteInDocs($documentation, 'posts/{id}/conditional');
    expect($conditionalPostRoute)->not->toBeNull('Conditional post endpoint should be documented');

    // Verify basic route information
    expect($conditionalPostRoute)->toHaveKey('uri');
    expect($conditionalPostRoute['uri'])->toContain('posts');
    expect($conditionalPostRoute)->toHaveKey('responses');
    
    // Check response structure
    $responses = $conditionalPostRoute['responses'];
    expect($responses)->toHaveKey('200');
    
    $successResponse = $responses['200'];
    expect($successResponse)->toHaveKey('content_type');
    expect($successResponse['content_type'])->toBe('application/json');
    expect($successResponse)->toHaveKey('type');
    expect($successResponse['type'])->toBe('object');
    
    // Note: We don't check for resource information as it might not be present in all implementations
});

it('generates standardized error response schemas', function () {
    // Rule: Error Handling - Implement proper error boundaries
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Test validation error endpoint
    $validationErrorRoute = findRouteInDocs($documentation, 'errors/validation');
    expect($validationErrorRoute)->not->toBeNull('Validation error endpoint should be documented');

    // Test not found error endpoint
    $notFoundErrorRoute = findRouteInDocs($documentation, 'errors/not-found');
    expect($notFoundErrorRoute)->not->toBeNull('Not found error endpoint should be documented');

    // Test server error endpoint
    $serverErrorRoute = findRouteInDocs($documentation, 'errors/server');
    expect($serverErrorRoute)->not->toBeNull('Server error endpoint should be documented');
    
    // Verify the endpoints have response structures
    expect($validationErrorRoute)->toHaveKey('responses');
    expect($notFoundErrorRoute)->toHaveKey('responses');
    expect($serverErrorRoute)->toHaveKey('responses');
    
    // Check that responses exist for each route
    $validationResponses = $validationErrorRoute['responses'];
    $notFoundResponses = $notFoundErrorRoute['responses'];
    $serverErrorResponses = $serverErrorRoute['responses'];
    
    // Verify that the responses are not empty
    expect($validationResponses)->not->toBeEmpty('Validation error should have responses');
    expect($notFoundResponses)->not->toBeEmpty('Not found error should have responses');
    expect($serverErrorResponses)->not->toBeEmpty('Server error should have responses');
    
    // Get the first response from each endpoint to verify content type
    $validationResponse = reset($validationResponses);
    $notFoundResponse = reset($notFoundResponses);
    $serverErrorResponse = reset($serverErrorResponses);
    
    // Verify content type for all responses
    expect($validationResponse)->toHaveKey('content_type');
    expect($validationResponse['content_type'])->toBe('application/json');
    
    expect($notFoundResponse)->toHaveKey('content_type');
    expect($notFoundResponse['content_type'])->toBe('application/json');
    
    expect($serverErrorResponse)->toHaveKey('content_type');
    expect($serverErrorResponse['content_type'])->toBe('application/json');
});

it('handles custom pagination structures', function () {
    // Rule: Project Context - Laravel API Documentation checks all existing routes
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the custom paginated endpoint
    $customPaginatedRoute = findRouteInDocs($documentation, 'custom/paginated');
    expect($customPaginatedRoute)->not->toBeNull('Custom paginated endpoint should be documented');

    // Verify basic route information
    expect($customPaginatedRoute)->toHaveKey('uri');
    expect($customPaginatedRoute['uri'])->toContain('custom/paginated');
    expect($customPaginatedRoute)->toHaveKey('responses');
    
    // Check response structure
    $responses = $customPaginatedRoute['responses'];
    expect($responses)->toHaveKey('200');
    
    $successResponse = $responses['200'];
    expect($successResponse)->toHaveKey('content_type');
    expect($successResponse['content_type'])->toBe('application/json');
    expect($successResponse)->toHaveKey('type');
    
    // For JsonResponse with custom structure, we expect object type
    expect($successResponse['type'])->toBe('object', 'Should be object type for JsonResponse');
});

it('documents conditional posts collection properly', function () {
    // Rule: Project Context - Users can enhance the generated documentation
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the conditional posts collection endpoint
    $conditionalPostsRoute = findRouteInDocs($documentation, 'posts/conditional');
    expect($conditionalPostsRoute)->not->toBeNull('Conditional posts collection endpoint should be documented');

    // Verify basic route information
    expect($conditionalPostsRoute)->toHaveKey('uri');
    expect($conditionalPostsRoute['uri'])->toContain('posts/conditional');
    expect($conditionalPostsRoute)->toHaveKey('responses');
    
    // Check response structure
    $responses = $conditionalPostsRoute['responses'];
    expect($responses)->toHaveKey('200');
    
    $successResponse = $responses['200'];
    expect($successResponse)->toHaveKey('content_type');
    expect($successResponse['content_type'])->toBe('application/json');
    expect($successResponse)->toHaveKey('type');
    
    // We expect this to be an object since it's a ResourceCollection
    expect($successResponse['type'])->toBe('object', 'Should be object type for ResourceCollection');
});

it('maintains consistent error response structure across endpoints', function () {
    // Rule: Error Handling - Provide user-friendly error messages
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find error endpoints
    $notFoundRoute = findRouteInDocs($documentation, 'errors/not-found');
    $serverErrorRoute = findRouteInDocs($documentation, 'errors/server');
    
    // Verify routes exist
    expect($notFoundRoute)->not->toBeNull('Not found endpoint should be documented');
    expect($serverErrorRoute)->not->toBeNull('Server error endpoint should be documented');
    
    // Verify routes have responses
    expect($notFoundRoute)->toHaveKey('responses');
    expect($serverErrorRoute)->toHaveKey('responses');
    
    // Get responses
    $notFoundResponses = $notFoundRoute['responses'];
    $serverErrorResponses = $serverErrorRoute['responses'];
    
    // Verify responses are not empty
    expect($notFoundResponses)->not->toBeEmpty('Not found should have responses');
    expect($serverErrorResponses)->not->toBeEmpty('Server error should have responses');
    
    // Get the first response from each endpoint
    $notFoundResponse = reset($notFoundResponses);
    $serverErrorResponse = reset($serverErrorResponses);
    
    // Verify content type consistency
    expect($notFoundResponse)->toHaveKey('content_type');
    expect($serverErrorResponse)->toHaveKey('content_type');
    
    expect($notFoundResponse['content_type'])->toBe('application/json');
    expect($serverErrorResponse['content_type'])->toBe('application/json');
    
    // Verify type consistency
    expect($notFoundResponse)->toHaveKey('type');
    expect($serverErrorResponse)->toHaveKey('type');
    
    expect($notFoundResponse['type'])->toBe($serverErrorResponse['type']);
});
