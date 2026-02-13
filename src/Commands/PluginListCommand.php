<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class PluginListCommand extends Command
{
    protected $signature = 'api:plugins';

    protected $description = 'List registered API documentation plugins';

    public function handle(PluginRegistry $registry): int
    {
        $plugins = $registry->getPlugins();

        if (empty($plugins)) {
            $this->info('No plugins registered.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($plugins as $plugin) {
            $interfaces = [];
            $ref = new \ReflectionClass($plugin);

            foreach ($ref->getInterfaceNames() as $interface) {
                $short = class_basename($interface);
                if ($short !== 'Plugin') {
                    $interfaces[] = $short;
                }
            }

            $rows[] = [
                $plugin->name(),
                get_class($plugin),
                $plugin->priority(),
                implode(', ', $interfaces),
            ];
        }

        $this->table(
            ['Name', 'Class', 'Priority', 'Capabilities'],
            $rows
        );

        // Summary
        $this->newLine();
        $this->info('Registered extractors:');
        $this->line('  Request body: '.count($registry->getRequestExtractors()));
        $this->line('  Response: '.count($registry->getResponseExtractors()));
        $this->line('  Query params: '.count($registry->getQueryExtractors()));
        $this->line('  Security: '.count($registry->getSecurityDetectors()));
        $this->line('  Transformers: '.count($registry->getOperationTransformers()));
        $this->line('  Exception providers: '.count($registry->getExceptionProviders()));

        return self::SUCCESS;
    }
}
