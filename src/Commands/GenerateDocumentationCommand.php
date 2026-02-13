<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Output\MultiDomainWriter;
use JkBennemann\LaravelApiDocumentation\Schema\ClassSchemaResolver;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;

class GenerateDocumentationCommand extends Command
{
    protected $signature = 'api:generate
        {--format=json : Output format (json, yaml, or postman)}
        {--domain= : Generate for specific domain only}
        {--route= : Generate for a single route URI (for debugging)}
        {--method=GET : HTTP method when using --route}
        {--dev : Include development servers}
        {--clear-cache : Clear AST cache before generating}
        {--verbose-analysis : Show analyzer decisions}
        {--watch : Watch for file changes and regenerate automatically}';

    protected $description = 'Generate OpenAPI documentation from route analysis';

    public function handle(
        RouteDiscovery $discovery,
        OpenApiEmitter $emitter,
        SchemaRegistry $registry,
        ClassSchemaResolver $classResolver,
    ): int {
        // Clear cache if requested
        if ($this->option('clear-cache')) {
            $cache = app(\JkBennemann\LaravelApiDocumentation\Cache\AstCache::class);
            $cache->clear();
            $this->info('AST cache cleared.');
        }

        $format = $this->option('format');
        $writer = new MultiDomainWriter;

        // Single route debugging mode
        if ($route = $this->option('route')) {
            return $this->generateSingleRoute($discovery, $emitter, $route);
        }

        // Process all configured documentation files
        $files = config('api-documentation.ui.storage.files', [
            'default' => [
                'name' => 'Default',
                'filename' => 'api-documentation',
                'process' => true,
            ],
        ]);

        $domains = config('api-documentation.domains', ['default' => []]);

        foreach ($files as $fileKey => $fileConfig) {
            if (! ($fileConfig['process'] ?? true)) {
                continue;
            }

            // If a specific domain was requested, skip others
            if ($this->option('domain') && $fileKey !== $this->option('domain')) {
                continue;
            }

            $this->info("Generating documentation: {$fileConfig['name']}...");

            // Reset registry and resolver cache for each file
            $registry->reset();
            $classResolver->reset();

            // Discover routes
            $contexts = $discovery->discover($fileKey === 'default' ? null : $fileKey);
            $this->info('  Found '.count($contexts).' routes');

            if (empty($contexts)) {
                $this->warn("  No routes found for '{$fileKey}'. Skipping.");

                continue;
            }

            // Build domain config
            $domainConfig = $domains[$fileKey] ?? $domains['default'] ?? [];
            $domainConfig = array_merge(
                [
                    'open_api_version' => config('api-documentation.open_api_version', '3.1.0'),
                    'version' => config('api-documentation.version', '1.0.0'),
                    'title' => config('api-documentation.title', 'API Documentation'),
                    'tags' => config('api-documentation.tags', []),
                    'tag_groups' => config('api-documentation.tag_groups', []),
                    'tag_groups_include_ungrouped' => config('api-documentation.tag_groups_include_ungrouped', true),
                    'trait_tags' => config('api-documentation.trait_tags', []),
                    'external_docs' => config('api-documentation.external_docs'),
                ],
                $domainConfig,
            );

            // Filter development servers unless --dev
            if (isset($domainConfig['servers']) && ! $this->option('dev')) {
                $domainConfig['servers'] = array_values(array_filter(
                    $domainConfig['servers'],
                    fn ($s) => ! ($s['development'] ?? false)
                ));
            }

            // Append alternative UI links if configured
            if ($domainConfig['append_alternative_uis'] ?? false) {
                $defaultUi = $domainConfig['default_ui'] ?? config('api-documentation.ui.default', 'swagger');
                $domainConfig['description'] = $writer->appendAlternativeUiLinks(
                    $domainConfig['description'] ?? '',
                    $defaultUi
                );
            }

            // Emit OpenAPI spec
            $bar = $this->output->createProgressBar(count($contexts));
            $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %message%');
            $bar->setMessage('Starting...');
            $bar->start();

            $spec = $emitter->emitWithProgress($contexts, $domainConfig, function (string $uri) use ($bar) {
                $bar->setMessage($uri);
                $bar->advance();
            });

            $bar->setMessage('Done');
            $bar->finish();
            $this->newLine();

            // Write output
            $filename = $fileConfig['filename'] ?? 'api-documentation';
            $path = $writer->write($spec, $filename, $format);

            $this->info("  Written to: {$path}");

            // Stats
            $pathCount = count($spec['paths'] ?? []);
            $componentCount = count($spec['components']['schemas'] ?? []);
            $this->info("  Paths: {$pathCount}, Components: {$componentCount}");
        }

        $this->info('Documentation generation complete.');

        // Watch mode
        if ($this->option('watch')) {
            return $this->watchAndRegenerate($discovery, $emitter, $registry, $classResolver);
        }

        return self::SUCCESS;
    }

    private function watchAndRegenerate(
        RouteDiscovery $discovery,
        OpenApiEmitter $emitter,
        SchemaRegistry $registry,
        ClassSchemaResolver $classResolver,
    ): int {
        $watchPaths = $this->resolveWatchPaths();
        $this->info('Watching for changes in: '.implode(', ', array_map('basename', $watchPaths)));
        $this->info('Press Ctrl+C to stop.');

        $lastHashes = $this->snapshotFileHashes($watchPaths);
        $running = true;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
            pcntl_signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
        }

        while ($running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            sleep(2);

            $currentHashes = $this->snapshotFileHashes($watchPaths);

            if ($currentHashes !== $lastHashes) {
                $this->newLine();
                $this->info('Changes detected, regenerating...');

                // Clear AST cache so changes are picked up
                $cache = app(\JkBennemann\LaravelApiDocumentation\Cache\AstCache::class);
                $cache->clear();

                // Re-run generation
                $format = $this->option('format');
                $writer = new MultiDomainWriter;
                $files = config('api-documentation.ui.storage.files', [
                    'default' => ['name' => 'Default', 'filename' => 'api-documentation', 'process' => true],
                ]);

                foreach ($files as $fileKey => $fileConfig) {
                    if (! ($fileConfig['process'] ?? true)) {
                        continue;
                    }

                    $registry->reset();
                    $classResolver->reset();
                    $contexts = $discovery->discover($fileKey === 'default' ? null : $fileKey);

                    if (empty($contexts)) {
                        continue;
                    }

                    $domainConfig = $this->buildDomainConfig($fileKey);

                    $spec = $emitter->emit($contexts, $domainConfig);

                    $filename = $fileConfig['filename'] ?? 'api-documentation';
                    $path = $writer->write($spec, $filename, $format);

                    $pathCount = count($spec['paths'] ?? []);
                    $this->info("  Regenerated: {$path} ({$pathCount} paths)");
                }

                $lastHashes = $currentHashes;
            }
        }

        $this->newLine();
        $this->info('Watch mode stopped.');

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function resolveWatchPaths(): array
    {
        $paths = [];

        // Watch controller directories
        foreach (['app/Http/Controllers', 'app/Http/Requests', 'app/Http/Resources'] as $dir) {
            $fullPath = base_path($dir);
            if (is_dir($fullPath)) {
                $paths[] = $fullPath;
            }
        }

        // Fallback to app directory
        if (empty($paths) && is_dir(base_path('app'))) {
            $paths[] = base_path('app');
        }

        return $paths;
    }

    /**
     * Build a hash map of all PHP files in the given directories.
     *
     * @param  string[]  $directories
     * @return array<string, string>
     */
    private function snapshotFileHashes(array $directories): array
    {
        $hashes = [];

        foreach ($directories as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $hashes[$file->getPathname()] = md5_file($file->getPathname());
                }
            }
        }

        return $hashes;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDomainConfig(string $fileKey): array
    {
        $domains = config('api-documentation.domains', ['default' => []]);
        $domainConfig = $domains[$fileKey] ?? $domains['default'] ?? [];

        return array_merge(
            [
                'open_api_version' => config('api-documentation.open_api_version', '3.1.0'),
                'version' => config('api-documentation.version', '1.0.0'),
                'title' => config('api-documentation.title', 'API Documentation'),
                'tags' => config('api-documentation.tags', []),
                'tag_groups' => config('api-documentation.tag_groups', []),
                'tag_groups_include_ungrouped' => config('api-documentation.tag_groups_include_ungrouped', true),
                'trait_tags' => config('api-documentation.trait_tags', []),
                'external_docs' => config('api-documentation.external_docs'),
            ],
            $domainConfig,
        );
    }

    private function generateSingleRoute(
        RouteDiscovery $discovery,
        OpenApiEmitter $emitter,
        string $route,
    ): int {
        $method = $this->option('method');
        $ctx = $discovery->discoverRoute($route, $method);

        if ($ctx === null) {
            $this->error("Route not found: {$method} {$route}");

            return self::FAILURE;
        }

        $this->info("Analyzing: {$method} {$route}");
        $this->info('Controller: '.($ctx->controllerClass() ?? 'Closure'));
        $this->info('Action: '.($ctx->actionMethod() ?? 'N/A'));

        $spec = $emitter->emit([$ctx], [
            'open_api_version' => config('api-documentation.open_api_version', '3.1.0'),
            'version' => config('api-documentation.version', '1.0.0'),
            'title' => 'Debug: '.$route,
        ]);

        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->line($json);

        return self::SUCCESS;
    }
}
