<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Commands\CaptureResponsesCommand;
use JkBennemann\LaravelApiDocumentation\Commands\LaravelApiDocumentationCommand;
use JkBennemann\LaravelApiDocumentation\Commands\ValidateDocumentationCommand;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\DefaultDocumentationController;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\RedocController;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\ScalarController;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\SwaggerController;
use JkBennemann\LaravelApiDocumentation\Services\AttributeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\CapturedResponseRepository;
use JkBennemann\LaravelApiDocumentation\Services\DocumentationValidator;
use JkBennemann\LaravelApiDocumentation\Services\EnhancedResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\ErrorMessageGenerator;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\RequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\TemplateManager;
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
            ->hasCommand(LaravelApiDocumentationCommand::class)
            ->hasCommand(CaptureResponsesCommand::class)
            ->hasCommand(ValidateDocumentationCommand::class);
    }

    public function packageRegistered(): void
    {
        // Register analyzer services
        $this->app->singleton(AttributeAnalyzer::class);
        $this->app->singleton(RequestAnalyzer::class);
        $this->app->singleton(ResponseAnalyzer::class);
        $this->app->singleton(TemplateManager::class);

        // Register capture-related services
        $this->app->singleton(CapturedResponseRepository::class);
        $this->app->singleton(DocumentationValidator::class);

        // Register ErrorMessageGenerator with all dependencies
        $this->app->singleton(ErrorMessageGenerator::class, function ($app) {
            return new ErrorMessageGenerator(
                $app['config'],
                $app->make(TemplateManager::class),
                $app->bound('translator') ? $app->make('translator') : null
            );
        });

        // Register enhanced response analyzer with proper dependencies
        $this->app->singleton(EnhancedResponseAnalyzer::class, function ($app) {
            return new EnhancedResponseAnalyzer(
                $app['config'],
                $app->make(ResponseAnalyzer::class),
                $app->make(RequestAnalyzer::class),
                $app->make(ErrorMessageGenerator::class)
            );
        });

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
            // Use DefaultDocumentationController to determine UI based on domain
            // Apply all possible middlewares (controller will handle routing to correct UI)
            $middlewares = array_unique(array_merge(
                config('api-documentation.ui.swagger.middleware', []),
                config('api-documentation.ui.redoc.middleware', []),
                config('api-documentation.ui.scalar.middleware', [])
            ));

            Route::get(
                '/documentation',
                [
                    DefaultDocumentationController::class,
                    'index',
                ]
            )
                ->middleware($middlewares)
                ->name('api-documentation.default');
        }
    }

    public function packageBooted(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-documentation.php', 'api-documentation');
    }
}
