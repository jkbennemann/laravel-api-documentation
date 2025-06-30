<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\AttributeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\RequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\EnhancedApiController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\CreateUserData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\UserData;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;
use ReflectionMethod;

class EnhancedApiDocumentationTest extends TestCase
{
    public function test_attribute_analyzer_extracts_query_parameters(): void
    {
        $analyzer = app(AttributeAnalyzer::class);
        $reflection = new ReflectionMethod(EnhancedApiController::class, 'index');

        $queryParams = $analyzer->extractQueryParameters($reflection);

        expect($queryParams)
            ->toBeArray()
            ->toHaveCount(4)
            ->and($queryParams['search'])
            ->toBeArray()
            ->toHaveKeys(['type', 'description', 'required'])
            ->and($queryParams['search']['type'])
            ->toBe('string')
            ->and($queryParams['search']['required'])
            ->toBeFalse()
            ->and($queryParams['status']['enum'])
            ->toBe(['active', 'inactive', 'pending']);
    }

    public function test_attribute_analyzer_extracts_request_body(): void
    {
        $analyzer = app(AttributeAnalyzer::class);
        $reflection = new ReflectionMethod(EnhancedApiController::class, 'store');

        $requestBody = $analyzer->extractRequestBody($reflection);

        expect($requestBody)
            ->toBeArray()
            ->toHaveKeys(['description', 'content_type', 'required', 'data_class'])
            ->and($requestBody['data_class'])
            ->toBe(CreateUserData::class)
            ->and($requestBody['required'])
            ->toBeTrue();
    }

    public function test_attribute_analyzer_extracts_response_bodies(): void
    {
        $analyzer = app(AttributeAnalyzer::class);
        $reflection = new ReflectionMethod(EnhancedApiController::class, 'store');

        $responses = $analyzer->extractResponseBodies($reflection);

        expect($responses)
            ->toBeArray()
            ->toHaveCount(2)
            ->toHaveKeys([201, 422])
            ->and($responses[201]['data_class'])
            ->toBe(UserData::class)
            ->and($responses[422]['data_class'])
            ->toBeNull();
    }

    public function test_attribute_analyzer_extracts_response_headers(): void
    {
        $analyzer = app(AttributeAnalyzer::class);
        $reflection = new ReflectionMethod(EnhancedApiController::class, 'index');

        $headers = $analyzer->extractResponseHeaders($reflection);

        expect($headers)
            ->toBeArray()
            ->toHaveCount(2)
            ->toHaveKeys(['X-Total-Count', 'X-Page-Count'])
            ->and($headers['X-Total-Count']['type'])
            ->toBe('integer');
    }

    public function test_enhanced_spatie_data_analysis(): void
    {
        $analyzer = app(ResponseAnalyzer::class);

        $schema = $analyzer->analyzeSpatieDataObject(CreateUserData::class);

        expect($schema)
            ->toBeArray()
            ->toHaveKeys(['type', 'properties', 'required'])
            ->and($schema['type'])
            ->toBe('object')
            ->and($schema['properties'])
            ->toHaveKeys(['name', 'email', 'password', 'is_active', 'preferences']) // snake_case mapping
            ->and($schema['required'])
            ->toContain('name', 'email') // Required fields
            ->not->toContain('password'); // Optional field
    }

    public function test_spatie_data_request_analysis(): void
    {
        $analyzer = app(RequestAnalyzer::class);

        $parameters = $analyzer->analyzeSpatieDataRequest(CreateUserData::class);

        expect($parameters)
            ->toBeArray()
            ->toHaveKeys(['name', 'email', 'password', 'is_active', 'preferences'])
            ->and($parameters['name']['type'])
            ->toBe('string')
            ->and($parameters['name']['required'])
            ->toBeTrue()
            ->and($parameters['password']['required'])
            ->toBeFalse()
            ->and($parameters['preferences']['type'])
            ->toBe('array');
    }

    public function test_full_openapi_generation_with_attributes(): void
    {
        // Set up routes with our enhanced controller
        Route::get('users', [EnhancedApiController::class, 'index'])->name('users.index');
        Route::post('users', [EnhancedApiController::class, 'store'])->name('users.store');

        $routeComposition = app(RouteComposition::class);
        $openApi = app(OpenApi::class);

        $routes = $routeComposition->process();
        $spec = $openApi->processRoutes($routes)->get();

        // Check that routes were processed
        expect($routes)
            ->toHaveCount(2);

        // Check that OpenAPI spec has paths and they're accessible
        expect($spec->paths)
            ->not()->toBeNull();

        // Check if paths have the users endpoint with expected operations
        $usersPath = $spec->paths['/users'] ?? null;
        expect($usersPath)
            ->not()->toBeNull()
            ->and($usersPath->get)
            ->not()->toBeNull()
            ->and($usersPath->post)
            ->not()->toBeNull();
    }
}
