<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SmartController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\AdvancedUserData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\UserData;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;
use openapiphp\openapi\spec\Response;
use openapiphp\openapi\spec\Schema;

class DynamicResponseTest extends TestCase
{
    /** @test */
    public function it_can_analyze_controller_method_with_single_return_type()
    {
        $analyzer = app(ResponseAnalyzer::class);
        $result = $analyzer->analyzeControllerMethod(SmartController::class, 'index');

        expect($result)
            ->toBeArray()
            ->toHaveKey('type')
            ->and($result['type'])
            ->toBe('array');
    }

    /** @test */
    public function it_can_analyze_spatie_data_object()
    {
        $analyzer = app(ResponseAnalyzer::class);
        $result = $analyzer->analyzeSpatieDataObject(UserData::class);

        expect($result)
            ->toBeArray()
            ->toHaveKey('type')
            ->and($result['type'])
            ->toBe('object')
            ->and($result['properties'])
            ->toHaveKeys(['id', 'name', 'email'])
            ->and($result['properties']['id']['type'])
            ->toBe('integer')
            ->and($result['properties']['name']['type'])
            ->toBe('string')
            ->and($result['properties']['email']['type'])
            ->toBe('string')
            ->and($result['required'])
            ->toContain('id', 'name', 'email');
    }

    /** @test */
    public function it_can_handle_dynamic_return_types()
    {
        Route::get('users/{userId}', [SmartController::class, 'getUserWithDynamicType']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        $apiService = app(OpenApi::class);
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/users/{userId}']->get->responses[200])
            ->toBeInstanceOf(Response::class)
            ->and($openApi->paths['/users/{userId}']->get->responses[200]->content['application/json']->schema)
            ->toBeInstanceOf(Schema::class);
    }

    /** @test */
    public function it_can_extract_query_parameters()
    {
        Route::get('users', [SmartController::class, 'index']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        $apiService = app(OpenApi::class);
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/users']->get->parameters)
            ->toBeArray()
            ->and($openApi->paths['/users']->get->parameters)
            ->toHaveCount(2)
            ->and($openApi->paths['/users']->get->parameters[0]->name)
            ->toBe('page')
            ->and($openApi->paths['/users']->get->parameters[0]->in)
            ->toBe('query')
            ->and($openApi->paths['/users']->get->parameters[0]->schema->type)
            ->toBe('integer');
    }
}
