<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\DocumentationBuilder;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SmartController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;
use openapiphp\openapi\Reader;

class OpenApiSmartFeaturesTest extends TestCase
{
    /** @test */
    public function it_automatically_handles_query_parameters()
    {
        Route::get('smart/users', [SmartController::class, 'index']);

        $this->artisan('documentation:generate')
            ->assertExitCode(0);

        $file = \Illuminate\Support\Facades\Storage::disk('public')->path('api-documentation.json');
        $openApi = Reader::readFromJson(file_get_contents($file));

        expect($openApi->paths['/smart/users']->get->parameters)
            ->not()->toBeEmpty();
    }

    /** @test */
    public function it_properly_documents_dynamic_response_types()
    {
        Route::get('smart/users/{userId}', [SmartController::class, 'getUserWithDynamicType']);

        $this->artisan('documentation:generate')
            ->assertExitCode(0);

        $file = \Illuminate\Support\Facades\Storage::disk('public')->path('api-documentation.json');
        $openApi = Reader::readFromJson(file_get_contents($file));

        expect($openApi->paths['/smart/users/{userId}']->get)
            ->not()->toBeNull()
            ->and($openApi->paths['/smart/users/{userId}']->get->responses[200])
            ->not()->toBeNull();
    }

    /** @test */
    public function it_documents_path_parameters_correctly()
    {
        Route::get('smart/users/{userId}', [SmartController::class, 'getUserWithDynamicType']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        expect($routeData)
            ->not()->toBeEmpty()
            ->and($routeData[0]['request_parameters'])
            ->toHaveKey('userId')
            ->and($routeData[0]['request_parameters']['userId']['type'])
            ->toBe('string');
    }

    /** @test */
    public function it_extracts_validation_requirements_from_controller()
    {
        Route::post('smart/users', [SmartController::class, 'storeWithValidation']);

        $service = app(RouteComposition::class);
        $routeData = $service->process();

        $apiService = app(OpenApi::class);
        $apiService->processRoutes($routeData);
        $openApi = $apiService->get();

        expect($openApi->paths['/smart/users']->post)
            ->not()->toBeNull();
    }
}
