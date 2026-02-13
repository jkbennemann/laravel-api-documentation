<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Output\TypeScriptGenerator;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;

class TypeScriptCommand extends Command
{
    protected $signature = 'api:types
        {--output= : Output file path (default: resources/js/types/api.d.ts)}
        {--file= : Path to an existing OpenAPI JSON file (instead of generating)}
        {--stdout : Print to stdout instead of writing to file}';

    protected $description = 'Generate TypeScript type definitions from the OpenAPI spec';

    public function handle(
        RouteDiscovery $discovery,
        OpenApiEmitter $emitter,
        SchemaRegistry $registry,
    ): int {
        $spec = $this->resolveSpec($discovery, $emitter, $registry);

        if ($spec === null) {
            return self::FAILURE;
        }

        $generator = new TypeScriptGenerator;

        if ($this->option('stdout')) {
            $this->line($generator->generate($spec));

            return self::SUCCESS;
        }

        $output = $this->option('output') ?? resource_path('js/types/api.d.ts');
        $generator->write($spec, $output);
        $this->info("TypeScript types written to: {$output}");

        return self::SUCCESS;
    }

    private function resolveSpec(RouteDiscovery $discovery, OpenApiEmitter $emitter, SchemaRegistry $registry): ?array
    {
        if ($file = $this->option('file')) {
            if (! file_exists($file)) {
                $this->error("File not found: {$file}");

                return null;
            }

            return json_decode(file_get_contents($file), true);
        }

        $registry->reset();
        $contexts = $discovery->discover();

        if (empty($contexts)) {
            $this->warn('No routes found.');

            return ['openapi' => '3.1.0', 'info' => [], 'paths' => []];
        }

        return $emitter->emit($contexts, array_merge(
            config('api-documentation', []),
            [
                'open_api_version' => config('api-documentation.open_api_version', '3.1.0'),
                'version' => config('api-documentation.version', '1.0.0'),
                'title' => config('api-documentation.title', 'API Documentation'),
            ],
        ));
    }
}
