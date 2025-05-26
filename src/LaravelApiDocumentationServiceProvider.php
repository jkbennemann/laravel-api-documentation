<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Commands\LaravelApiDocumentationCommand;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\RedocController;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\ScalarController;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\SwaggerController;
use JkBennemann\LaravelApiDocumentation\Services\AttributeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\RequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelApiDocumentationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-api-documentation')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommand(LaravelApiDocumentationCommand::class);
    }

    public function packageRegistered(): void
    {
        // Register analyzer services
        $this->app->singleton(AttributeAnalyzer::class);
        $this->app->singleton(RequestAnalyzer::class);
        $this->app->singleton(ResponseAnalyzer::class);

        // Register OpenApi service with proper dependency injection
        $this->app->singleton(OpenApi::class, function ($app) {
            return new OpenApi(
                $app['config'],
                $app->make(AttributeAnalyzer::class)
            );
        });

        if (config('api-documentation.ui.swagger.enabled', true)) {
            Route::get(
                config('api-documentation.ui.swagger.route'),
                [
                    SwaggerController::class,
                    'index',
                ]
            )
                ->middleware(config('api-documentation.ui.swagger.middleware', []))
                ->name('api-documentation.swagger');
        }

        if (config('api-documentation.ui.redoc.enabled', true)) {
            Route::get(
                config('api-documentation.ui.redoc.route'),
                [
                    RedocController::class,
                    'index',
                ]
            )
                ->middleware(config('api-documentation.ui.redoc.middleware', []))
                ->name('api-documentation.redoc');
        }

        if (config('api-documentation.ui.scalar.enabled', true)) {
            Route::get(
                config('api-documentation.ui.scalar.route'),
                [
                    ScalarController::class,
                    'index',
                ]
            )
                ->middleware(config('api-documentation.ui.scalar.middleware', []))
                ->name('api-documentation.scalar');
        }

        if (config('api-documentation.ui.swagger.enabled', false) ||
            config('api-documentation.ui.redoc.enabled', false) ||
            config('api-documentation.ui.scalar.enabled', false)
        ) {
            if (config('api-documentation.ui.default', false) === 'redoc') {
                Route::get(
                    '/documentation',
                    [
                        RedocController::class,
                        'index',
                    ]
                )
                    ->middleware(config('api-documentation.ui.redoc.middleware', []));
            } elseif (config('api-documentation.ui.default', false) === 'scalar') {
                Route::get(
                    '/documentation',
                    [
                        ScalarController::class,
                        'index',
                    ]
                )
                    ->middleware(config('api-documentation.ui.scalar.middleware', []));
            } else {
                Route::get(
                    '/documentation',
                    [
                        SwaggerController::class,
                        'index',
                    ]
                )
                    ->middleware(config('api-documentation.ui.swagger.middleware', []));
            }

        }
    }

    public function packageBooted(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-documentation.php', 'api-documentation');
    }
}
