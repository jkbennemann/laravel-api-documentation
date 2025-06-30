<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\AttributeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SmartController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;
use openapiphp\openapi\spec\RequestBody;
use openapiphp\openapi\spec\Response;
use openapiphp\openapi\spec\Schema;

class SmartFeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('api-documentation.smart_features', true);
    }

    /** @test */
    public function it_can_detect_request_validation_rules()
    {
        Route::post('users', [SmartController::class, 'storeWithValidation']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        // Create fresh OpenApi instance to avoid singleton state pollution
        $apiService = new OpenApi(
            app('config'),
            app(AttributeAnalyzer::class)
        );
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/users']->post->requestBody)
            ->toBeInstanceOf(RequestBody::class)
            ->and($openApi->paths['/users']->post->requestBody->content['application/json']->schema)
            ->toBeInstanceOf(Schema::class)
            ->and($openApi->paths['/users']->post->requestBody->content['application/json']->schema->properties)
            ->toHaveKeys(['name', 'email', 'password'])
            ->and($openApi->paths['/users']->post->requestBody->content['application/json']->schema->required)
            ->toContain('name', 'email', 'password');
    }

    /** @test */
    public function it_can_detect_response_types_from_return_type()
    {
        Route::get('users', [SmartController::class, 'index']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        // Create fresh OpenApi instance to avoid singleton state pollution
        $apiService = new OpenApi(
            app('config'),
            app(AttributeAnalyzer::class)
        );
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/users']->get->responses[200])
            ->toBeInstanceOf(Response::class)
            ->and($openApi->paths['/users']->get->responses[200]->content['application/json']->schema)
            ->toBeInstanceOf(Schema::class)
            ->and($openApi->paths['/users']->get->responses[200]->content['application/json']->schema->type)
            ->toBe('array');
    }

    /** @test */
    public function it_can_detect_paginated_responses()
    {
        Route::get('users/paginated', [SmartController::class, 'paginated']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        // Create fresh OpenApi instance to avoid singleton state pollution
        $apiService = new OpenApi(
            app('config'),
            app(AttributeAnalyzer::class)
        );
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/users/paginated']->get->responses[200])
            ->toBeInstanceOf(Response::class)
            ->and($openApi->paths['/users/paginated']->get->responses[200]->content['application/json']->schema)
            ->toBeInstanceOf(Schema::class)
            ->and($openApi->paths['/users/paginated']->get->responses[200]->content['application/json']->schema->properties)
            ->toHaveKeys(['data', 'meta', 'links']);
    }

    /** @test */
    public function it_can_detect_error_responses()
    {
        Route::post('users/error', [SmartController::class, 'errorResponse']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        // Create fresh OpenApi instance to avoid singleton state pollution
        $apiService = new OpenApi(
            app('config'),
            app(AttributeAnalyzer::class)
        );
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/users/error']->post->responses)
            ->toHaveKeys(['422'])
            ->and($openApi->paths['/users/error']->post->responses[422]->content['application/json']->schema)
            ->toBeInstanceOf(Schema::class)
            ->and($openApi->paths['/users/error']->post->responses[422]->content['application/json']->schema->properties)
            ->toHaveKeys(['message', 'errors']);
    }

    /** @test */
    public function it_can_detect_route_model_binding()
    {
        Route::get('users/{user}', [SmartController::class, 'show']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        // Create fresh OpenApi instance to avoid singleton state pollution
        $apiService = new OpenApi(
            app('config'),
            app(AttributeAnalyzer::class)
        );
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/users/{user}']->get->parameters)
            ->toHaveCount(1)
            ->and($openApi->paths['/users/{user}']->get->parameters[0]->name)
            ->toBe('user')
            ->and($openApi->paths['/users/{user}']->get->parameters[0]->in)
            ->toBe('path')
            ->and($openApi->paths['/users/{user}']->get->parameters[0]->required)
            ->toBeTrue();
    }

    /** @test */
    public function it_respects_disabled_smart_features()
    {
        config()->set('api-documentation.smart_features', false);
        Route::post('users', [SmartController::class, 'storeWithValidation']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        // Create fresh OpenApi instance to avoid singleton state pollution
        $apiService = new OpenApi(
            app('config'),
            app(AttributeAnalyzer::class)
        );
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/users']->post->requestBody)
            ->toBeNull();
    }
}
