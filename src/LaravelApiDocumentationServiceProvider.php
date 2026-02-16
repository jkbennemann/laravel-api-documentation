<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Analyzers\AnalysisPipeline;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\AuthenticationErrorAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\AuthorizationErrorAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\ConfiguredErrorAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\ExceptionHandlerAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\ExceptionHandlerSchemaAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\NotFoundErrorAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\RateLimitErrorAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Error\ValidationErrorAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam\FormRequestQueryAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam\PaginationAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam\PhpDocQueryParameterAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam\QueryParameterAttributeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam\RequestMethodCallAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\QueryParam\RuntimeCaptureQueryAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Request\ContainerFormRequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Request\FormRequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Request\InlineValidationAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Request\RequestBodyAttributeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Request\RuntimeCaptureRequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Response\DataResponseAttributeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Response\ReturnTypeAnalyzer;
use JkBennemann\LaravelApiDocumentation\Analyzers\Response\RuntimeCaptureResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Cache\AstCache;
use JkBennemann\LaravelApiDocumentation\Commands\ClearCacheCommand;
use JkBennemann\LaravelApiDocumentation\Commands\DiffCommand;
use JkBennemann\LaravelApiDocumentation\Commands\GenerateDocumentationCommand;
use JkBennemann\LaravelApiDocumentation\Commands\LintCommand;
use JkBennemann\LaravelApiDocumentation\Commands\PluginListCommand;
use JkBennemann\LaravelApiDocumentation\Commands\TypeScriptCommand;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\DefaultDocumentationController;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\RedocController;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\ScalarController;
use JkBennemann\LaravelApiDocumentation\Http\Controllers\SwaggerController;
use JkBennemann\LaravelApiDocumentation\Plugins\BearerAuthPlugin;
use JkBennemann\LaravelApiDocumentation\Plugins\CodeSamplePlugin;
use JkBennemann\LaravelApiDocumentation\Plugins\JsonApiPlugin;
use JkBennemann\LaravelApiDocumentation\Plugins\LaravelActionsPlugin;
use JkBennemann\LaravelApiDocumentation\Plugins\PaginationPlugin;
use JkBennemann\LaravelApiDocumentation\Plugins\SpatieDataPlugin;
use JkBennemann\LaravelApiDocumentation\Plugins\SpatieQueryBuilderPlugin;
use JkBennemann\LaravelApiDocumentation\Repository\CapturedResponseRepository;
use JkBennemann\LaravelApiDocumentation\Schema\ClassSchemaResolver;
use JkBennemann\LaravelApiDocumentation\Schema\EloquentModelAnalyzer;
use JkBennemann\LaravelApiDocumentation\Schema\PhpDocParser;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;
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
            ->hasCommand(GenerateDocumentationCommand::class)
            ->hasCommand(ClearCacheCommand::class)
            ->hasCommand(PluginListCommand::class)
            ->hasCommand(LintCommand::class)
            ->hasCommand(DiffCommand::class)
            ->hasCommand(TypeScriptCommand::class);
    }

    public function packageRegistered(): void
    {
        // Core singletons
        $this->app->singleton(SchemaRegistry::class);
        $this->app->singleton(CapturedResponseRepository::class);
        $this->app->singleton(TypeMapper::class);
        $this->app->singleton(ExceptionHandlerSchemaAnalyzer::class);

        $this->app->singleton(ClassSchemaResolver::class, function ($app) {
            $typeMapper = $app->make(TypeMapper::class);
            $resolver = new ClassSchemaResolver(
                $app->make(SchemaRegistry::class),
                $typeMapper,
            );
            $typeMapper->setClassResolver($resolver);

            return $resolver;
        });

        $this->app->singleton(EloquentModelAnalyzer::class, function ($app) {
            return new EloquentModelAnalyzer($app->make(TypeMapper::class));
        });

        $this->app->singleton(PhpDocParser::class, function ($app) {
            $parser = new PhpDocParser;
            $parser->setClassResolver($app->make(ClassSchemaResolver::class));

            return $parser;
        });

        $this->app->singleton(AstCache::class, function ($app) {
            $config = $app['config']['api-documentation'] ?? [];

            return new AstCache(
                $config['analysis']['cache_path'] ?? null,
                $config['analysis']['cache_ttl'] ?? 3600,
            );
        });

        // Plugin Registry
        $this->app->singleton(PluginRegistry::class, function ($app) {
            $registry = new PluginRegistry;
            $config = $app['config']['api-documentation'] ?? [];

            // Register core analyzers as extractors
            $this->registerCoreAnalyzers($registry, $config, $app);

            // Register built-in plugins
            $this->registerBuiltInPlugins($registry, $app);

            // Register user-configured plugins
            $this->registerConfiguredPlugins($registry, $config);

            // Auto-discover plugins from composer packages
            $this->autoDiscoverPlugins($registry);

            return $registry;
        });

        // Analysis Pipeline
        $this->app->singleton(AnalysisPipeline::class, function ($app) {
            return new AnalysisPipeline($app->make(PluginRegistry::class));
        });

        // Route Discovery
        $this->app->singleton(RouteDiscovery::class, function ($app) {
            return new RouteDiscovery(
                $app['router'],
                $app['config']['api-documentation'] ?? [],
            );
        });

        // OpenAPI Emitter
        $this->app->singleton(OpenApiEmitter::class, function ($app) {
            return new OpenApiEmitter(
                $app->make(AnalysisPipeline::class),
                $app->make(SchemaRegistry::class),
                $app['config']['api-documentation'] ?? [],
                $app->make(PhpDocParser::class),
            );
        });

        // Register UI routes
        $this->registerUiRoutes();
    }

    public function packageBooted(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-documentation.php', 'api-documentation');
    }

    private function registerCoreAnalyzers(PluginRegistry $registry, array $config, $app): void
    {
        $capturedRepo = $app->make(CapturedResponseRepository::class);
        $schemaRegistry = $app->make(SchemaRegistry::class);
        $astCache = $app->make(AstCache::class);

        // Request extractors (highest priority first)
        $registry->addRequestExtractor(new RequestBodyAttributeAnalyzer, 100);
        $formRequestAnalyzer = new FormRequestAnalyzer($config, $astCache, $schemaRegistry);
        $registry->addRequestExtractor($formRequestAnalyzer, 90);
        $registry->addRequestExtractor(new ContainerFormRequestAnalyzer($formRequestAnalyzer, $config, $astCache, $schemaRegistry), 85);
        $registry->addRequestExtractor(new InlineValidationAnalyzer($config, $schemaRegistry), 80);
        $registry->addRequestExtractor(new RuntimeCaptureRequestAnalyzer($capturedRepo), 60);

        // Query parameter extractors
        $registry->addQueryExtractor(new QueryParameterAttributeAnalyzer, 100);
        $registry->addQueryExtractor(new FormRequestQueryAnalyzer($config), 85);
        $registry->addQueryExtractor(new PaginationAnalyzer, 80);
        $registry->addQueryExtractor(new PhpDocQueryParameterAnalyzer($app->make(PhpDocParser::class)), 75);
        $registry->addQueryExtractor(new RuntimeCaptureQueryAnalyzer($capturedRepo), 70);
        $registry->addQueryExtractor(new RequestMethodCallAnalyzer, 65);

        // Response extractors
        $registry->addResponseExtractor(new DataResponseAttributeAnalyzer($schemaRegistry, $config), 100);

        $classResolver = $app->make(ClassSchemaResolver::class);
        $modelAnalyzer = $app->make(EloquentModelAnalyzer::class);
        $phpDocParser = $app->make(PhpDocParser::class);
        $returnTypeAnalyzer = new ReturnTypeAnalyzer($schemaRegistry, $classResolver, $modelAnalyzer, $phpDocParser, $config, $astCache);
        $registry->addResponseExtractor($returnTypeAnalyzer, 90);

        $registry->addResponseExtractor(new RuntimeCaptureResponseAnalyzer($capturedRepo), 70);

        // Error extractors
        $handlerAnalyzer = $app->make(ExceptionHandlerSchemaAnalyzer::class);
        $registry->addResponseExtractor(new ValidationErrorAnalyzer($handlerAnalyzer), 100);
        $registry->addResponseExtractor(new AuthenticationErrorAnalyzer($handlerAnalyzer), 90);
        $registry->addResponseExtractor(new AuthorizationErrorAnalyzer($handlerAnalyzer), 90);
        $registry->addResponseExtractor(new NotFoundErrorAnalyzer($handlerAnalyzer), 80);
        $registry->addResponseExtractor(new RateLimitErrorAnalyzer($handlerAnalyzer), 75);
        $exceptionAnalyzer = new ExceptionHandlerAnalyzer($registry->getExceptionProviders(), $handlerAnalyzer, $phpDocParser);
        $registry->addResponseExtractor($exceptionAnalyzer, 70);
        $registry->addResponseExtractor(new ConfiguredErrorAnalyzer($config, $handlerAnalyzer), 60);
    }

    private function registerBuiltInPlugins(PluginRegistry $registry, $app): void
    {
        // Bearer auth (always enabled)
        $registry->register(new BearerAuthPlugin);

        // Pagination (always enabled)
        $registry->register(new PaginationPlugin);

        // Code samples (enabled via config)
        $codeSamplesConfig = $app['config']['api-documentation']['code_samples'] ?? [];
        if ($codeSamplesConfig['enabled'] ?? false) {
            $registry->register(new CodeSamplePlugin(
                languages: $codeSamplesConfig['languages'] ?? null,
                baseUrl: $codeSamplesConfig['base_url'] ?? null,
                schemaRegistry: $app->make(SchemaRegistry::class),
            ));
        }

        // Spatie Data (only when installed)
        if (class_exists(\Spatie\LaravelData\Data::class)) {
            $plugin = new SpatieDataPlugin;
            $plugin->setRegistry($app->make(SchemaRegistry::class));
            $plugin->setClassResolver($app->make(ClassSchemaResolver::class));
            $registry->register($plugin);
        }

        // Spatie Query Builder (only when installed)
        if (class_exists(\Spatie\QueryBuilder\QueryBuilder::class)) {
            $registry->register(new SpatieQueryBuilderPlugin);
        }

        // JSON:API (timacdonald/json-api, only when installed)
        if (class_exists('TiMacDo\JsonApi\JsonApiResource')) {
            $registry->register(new JsonApiPlugin);
        }

        // Laravel Actions (lorisleiva/laravel-actions, only when installed)
        if (trait_exists('Lorisleiva\Actions\Concerns\AsController')) {
            $registry->register(new LaravelActionsPlugin);
        }
    }

    private function registerConfiguredPlugins(PluginRegistry $registry, array $config): void
    {
        $plugins = $config['plugins'] ?? [];

        foreach ($plugins as $pluginClass) {
            if (is_string($pluginClass) && class_exists($pluginClass)) {
                $plugin = app($pluginClass);
                if ($plugin instanceof Plugin) {
                    $registry->register($plugin);
                }
            }
        }
    }

    private function autoDiscoverPlugins(PluginRegistry $registry): void
    {
        // Check composer installed packages for api-documentation plugin extra
        $composerLock = base_path('composer.lock');
        if (! file_exists($composerLock)) {
            return;
        }

        try {
            $lock = json_decode(file_get_contents($composerLock), true);
            $packages = array_merge(
                $lock['packages'] ?? [],
                $lock['packages-dev'] ?? []
            );

            foreach ($packages as $package) {
                $extra = $package['extra']['api-documentation'] ?? null;
                if ($extra === null) {
                    continue;
                }

                $pluginClasses = $extra['plugins'] ?? [];
                foreach ($pluginClasses as $pluginClass) {
                    if (is_string($pluginClass) && class_exists($pluginClass)) {
                        $plugin = app($pluginClass);
                        if ($plugin instanceof Plugin && ! $registry->hasPlugin($plugin->name())) {
                            $registry->register($plugin);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Plugin auto-discovery failed: {$e->getMessage()}");
            }
        }
    }

    private function registerUiRoutes(): void
    {
        if (config('api-documentation.ui.swagger.enabled', false)) {
            Route::get(
                config('api-documentation.ui.swagger.route'),
                [SwaggerController::class, 'index']
            )
                ->middleware(config('api-documentation.ui.swagger.middleware', []))
                ->name('api-documentation.swagger');
        }

        if (config('api-documentation.ui.redoc.enabled', false)) {
            Route::get(
                config('api-documentation.ui.redoc.route'),
                [RedocController::class, 'index']
            )
                ->middleware(config('api-documentation.ui.redoc.middleware', []))
                ->name('api-documentation.redoc');
        }

        if (config('api-documentation.ui.scalar.enabled', false)) {
            Route::get(
                config('api-documentation.ui.scalar.route'),
                [ScalarController::class, 'index']
            )
                ->middleware(config('api-documentation.ui.scalar.middleware', []))
                ->name('api-documentation.scalar');
        }

        if (config('api-documentation.ui.swagger.enabled', false) ||
            config('api-documentation.ui.redoc.enabled', false) ||
            config('api-documentation.ui.scalar.enabled', false)
        ) {
            $middlewares = array_unique(array_merge(
                config('api-documentation.ui.swagger.middleware', []),
                config('api-documentation.ui.redoc.middleware', []),
                config('api-documentation.ui.scalar.middleware', [])
            ));

            Route::get(
                '/documentation',
                [DefaultDocumentationController::class, 'index']
            )
                ->middleware($middlewares)
                ->name('api-documentation.default');
        }
    }
}
