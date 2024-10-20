<?php

declare(strict_types=1);

namespace Bennemann\LaravelApiDocumentation;

use Bennemann\LaravelApiDocumentation\Http\Controllers\RedocController;
use Bennemann\LaravelApiDocumentation\Http\Controllers\SwaggerController;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Bennemann\LaravelApiDocumentation\Commands\LaravelApiDocumentationCommand;

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
        if (config('api-documentation.ui.swagger.enabled', true)) {
            Route::get(
                config('api-documentation.ui.swagger.route'),
                [
                    SwaggerController::class,
                    'index'
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
                    'index'
                ]
            )
                ->middleware(config('api-documentation.ui.redoc.middleware', []))
                ->name('api-documentation.redoc');
        }

        if (config('api-documentation.ui.swagger.enabled', false) || config('api-documentation.ui.redoc.enabled', false)) {
            if (config('api-documentation.ui.default', false) === 'redoc') {
                Route::get(
                    '/documentation',
                    [
                        RedocController::class,
                        'index'
                    ]
                )
                    ->middleware(config('api-documentation.ui.redoc.middleware', []));
            } else {
                Route::get(
                    '/documentation',
                    [
                        SwaggerController::class,
                        'index'
                    ]
                )
                    ->middleware(config('api-documentation.ui.swagger.middleware', []));
            }

        }
    }
}
