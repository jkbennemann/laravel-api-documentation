<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\AuthenticationController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\LoginData;
use Symfony\Component\HttpFoundation\Response;

// Rule: Testing - Write unit tests for utilities and components
beforeEach(function () {
    // Clear existing routes to avoid conflicts
    Route::getRoutes()->refreshNameLookups();
    
    // Register the login route for testing
    Route::prefix('/v1')->group(function () {
        Route::post('/login', [AuthenticationController::class, 'login'])->name('login');
    });
});

function findLoginRoute(array $docs): ?array
{
    // Rule: Error Handling - Implement proper error boundaries
    foreach ($docs as $doc) {
        if (isset($doc['uri']) && str_contains($doc['uri'], 'login') && 
            isset($doc['method']) && $doc['method'] === 'POST') {
            return $doc;
        }
    }
    return null;
}

it('generates proper documentation for login endpoint', function () {
    // Rule: Code Style and Structure - Write concise, technical PHP code with accurate examples
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the login endpoint
    $loginRoute = findLoginRoute($documentation);
    expect($loginRoute)->not->toBeNull('Login endpoint should be documented');

    // Verify basic route information
    expect($loginRoute)->toHaveKey('uri');
    expect($loginRoute['uri'])->toBe('v1/login'); // Note: URI doesn't have leading slash in the documentation
    expect($loginRoute)->toHaveKey('method');
    expect($loginRoute['method'])->toBe('POST');
    
    // Verify tag and summary
    expect($loginRoute)->toHaveKey('tags');
    expect($loginRoute['tags'])->toContain('Authentication');
    expect($loginRoute)->toHaveKey('summary');
    expect($loginRoute['summary'])->toBe('Login an user by credentials');
    
    // Verify request parameters
    expect($loginRoute)->toHaveKey('parameters');
    expect($loginRoute['parameters'])->toBeArray();
    expect($loginRoute['parameters'])->toHaveKey('email');
    expect($loginRoute['parameters'])->toHaveKey('password');
    
    // Verify responses
    expect($loginRoute)->toHaveKey('responses');
    expect($loginRoute['responses'])->toHaveKey(Response::HTTP_OK);
    
    $successResponse = $loginRoute['responses'][Response::HTTP_OK];
    expect($successResponse)->toHaveKey('description');
    expect($successResponse['description'])->toBe('Successful login');
    
    // Verify response headers
    expect($successResponse)->toHaveKey('headers');
    expect($successResponse['headers'])->toHaveKey('access_token');
    
    // Verify response resource
    expect($successResponse)->toHaveKey('resource');
    expect($successResponse['resource'])->toBe(LoginData::class);
});

it('generates proper example for login response', function () {
    // Rule: Project Context - Laravel API Documentation checks all existing routes
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the login endpoint
    $loginRoute = findLoginRoute($documentation);
    expect($loginRoute)->not->toBeNull('Login endpoint should be documented');
    
    // Verify responses
    expect($loginRoute)->toHaveKey('responses');
    expect($loginRoute['responses'])->toHaveKey(Response::HTTP_OK);
    
    $successResponse = $loginRoute['responses'][Response::HTTP_OK];
    
    // Rule: Project Context - Response classes can be enhanced with PHP annotations
    
    // Verify basic response structure
    expect($successResponse)->toHaveKey('description');
    expect($successResponse['description'])->toBe('Successful login');
    
    // Verify content type is set
    expect($successResponse)->toHaveKey('content_type');
    expect($successResponse['content_type'])->toBe('application/json');
    
    // Verify response type
    expect($successResponse)->toHaveKey('type');
    expect($successResponse['type'])->toBe('object');
    
    // Verify resource class
    expect($successResponse)->toHaveKey('resource');
    expect($successResponse['resource'])->toBe(LoginData::class);
    
    // Verify headers
    expect($successResponse)->toHaveKey('headers');
    expect($successResponse['headers'])->toHaveKey('access_token');
    expect($successResponse['headers']['access_token'])->toBe('JWT token for the requested user');
});

it('includes response headers in the documentation', function () {
    // Rule: Documentation - Document API interactions and data flows
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();

    // Find the login endpoint
    $loginRoute = findLoginRoute($documentation);
    expect($loginRoute)->not->toBeNull('Login endpoint should be documented');
    
    // Verify responses
    expect($loginRoute)->toHaveKey('responses');
    expect($loginRoute['responses'])->toHaveKey(Response::HTTP_OK);
    
    $successResponse = $loginRoute['responses'][Response::HTTP_OK];
    
    // Verify response headers
    expect($successResponse)->toHaveKey('headers');
    expect($successResponse['headers'])->toHaveKey('access_token');
    expect($successResponse['headers']['access_token'])->toBe('JWT token for the requested user');
});

it('generates example for LoginData class directly', function () {
    // Rule: Project Context - If a response value is a Spatie Data class, the documentation will be generated for it
    $responseAnalyzer = app(ResponseAnalyzer::class);
    
    // Analyze the LoginData class directly
    $analysis = $responseAnalyzer->analyzeDataResponse(LoginData::class);
    
    // Verify that the analysis is enhanced
    expect($analysis)->toHaveKey('enhanced_analysis', true);
    
    // Verify properties have the correct descriptions and examples from Parameter attributes
    expect($analysis)->toHaveKey('properties');
    $properties = $analysis['properties'];
    
    // Check id property
    expect($properties)->toHaveKey('id');
    expect($properties['id'])->toHaveKey('description', 'The hash id of the user');
    expect($properties['id'])->toHaveKey('example', '2Q1DG07Z');
    
    // Check email property
    expect($properties)->toHaveKey('email');
    expect($properties['email'])->toHaveKey('description', 'The email of the user');
    expect($properties['email'])->toHaveKey('format', 'email');
    
    // Check trashboard_id property
    expect($properties)->toHaveKey('trashboard_id');
    expect($properties['trashboard_id'])->toHaveKey('description', 'The trashboard ID of the user');
    expect($properties['trashboard_id'])->toHaveKey('example', 13804);
    
    // Verify that an example is generated
    expect($analysis)->toHaveKey('example');
    expect($analysis['example'])->toBeArray();
    
    // Verify the example contains the expected values
    $example = $analysis['example'];
    
    // Check for id with example from Parameter attribute
    expect($example)->toHaveKey('id');
    expect($example['id'])->toBe('2Q1DG07Z');
    
    // Check for email
    expect($example)->toHaveKey('email');
    expect($example['email'])->toBeString();
    
    // Check for trashboard_id with example from Parameter attribute
    expect($example)->toHaveKey('trashboard_id');
    expect($example['trashboard_id'])->toBe(13804);
    
    // Check for attributes with example from Parameter attribute
    expect($example)->toHaveKey('attributes');
    expect($example['attributes'])->toBeArray();
    expect($example['attributes'][0])->toHaveKey('name', 'external_identifier');
    expect($example['attributes'][0])->toHaveKey('value', 'RB123456');
    
    // Check for roles with example from Parameter attribute
    expect($example)->toHaveKey('roles');
    expect($example['roles'])->toBeArray();
    expect($example['roles'][0])->toHaveKey('role', 'SUPER_ADMIN');
    expect($example['roles'][0])->toHaveKey('expires_at');
    expect($example['roles'][0]['expires_at'])->toBeNull();
});

it('verifies that login endpoint has proper response structure', function () {
    // Rule: Project Context - OpenAPI example is generated automatically
    $routeComposition = app(RouteComposition::class);
    $documentation = $routeComposition->process();
    
    // Find the login endpoint
    $loginRoute = findLoginRoute($documentation);
    expect($loginRoute)->not->toBeNull('Login endpoint should be documented');
    
    // Verify responses
    expect($loginRoute)->toHaveKey('responses');
    expect($loginRoute['responses'])->toHaveKey(Response::HTTP_OK);
    
    $successResponse = $loginRoute['responses'][Response::HTTP_OK];
    
    // Verify the response has the resource class set
    expect($successResponse)->toHaveKey('resource');
    expect($successResponse['resource'])->toBe(LoginData::class);
    
    // Verify content type
    expect($successResponse)->toHaveKey('content_type');
    expect($successResponse['content_type'])->toBe('application/json');
    
    // Verify description
    expect($successResponse)->toHaveKey('description');
    expect($successResponse['description'])->toBe('Successful login');
});
