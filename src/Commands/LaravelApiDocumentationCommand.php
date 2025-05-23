<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\Exceptions\DocumentationException;
use JkBennemann\LaravelApiDocumentation\Services\DocumentationBuilder;

class LaravelApiDocumentationCommand extends Command
{
    public $signature = 'documentation:generate {--file= : Optional file key from config to generate only one specific documentation file}';

    public $description = 'Documentation generator for Laravel API';

    public function handle(DocumentationBuilder $builder): int
    {
        $this->comment('Generating documentation...');

        $docFiles = config('api-documentation.ui.storage.files', []);
        $specificFile = $this->option('file');

        // If a specific file is requested, only process that one
        if ($specificFile) {
            if (!isset($docFiles[$specificFile])) {
                $this->error("Documentation file '{$specificFile}' is not defined in config.");
                return self::FAILURE;
            }

            $docFiles = [$specificFile => $docFiles[$specificFile]];
        }

        foreach ($docFiles as $docName => $file) {
            // Delete all non-processable files
            if (!isset($file['process']) || !$file['process']) {
                unset($docFiles[$docName]);
                continue;
            }

            if (!isset($file['filename'])) {
                $this->error("Configuration error at doc {$docName} - all storages must have a filename");
                continue;
            }

            try {
                $this->info("Generating documentation for '{$docName}'...");
                foreach ($builder->build($file['filename'], $file['name'] ?? null, $docName) as $message) {
                    $this->info("  - {$message}");
                }
                $this->newLine();
            } catch (DocumentationException $e) {
                $this->error($e->getMessage());
                if ($specificFile) {
                    return self::FAILURE;
                }
                continue;
            }
        }

        if (empty($docFiles)) {
            // If there are no processable doc files - work with single json file the old way
            // (for backward compatibility)
            $filename = config('api-documentation.ui.storage.filename', 'api-documentation.json');

            try {
                $this->info("Generating default documentation...");
                foreach ($builder->build($filename) as $message) {
                    $this->info("  - {$message}");
                }
                $this->newLine();
            } catch (DocumentationException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
        }

        // Display documentation URLs
        if (config('api-documentation.ui.swagger.enabled', false) === true ||
            config('api-documentation.ui.redoc.enabled', false) === true) {
            $appURL = config('app.url').(config('api-documentation.app.port') ? ':'.config('api-documentation.app.port') : '');
            $default = config('api-documentation.ui.default', false);

            $this->line('You can view the documentation at:');
            $this->line('Default Documentation ('.$default.'): '.$appURL.'/documentation');

            if (config('api-documentation.ui.swagger.enabled', false) === true) {
                $this->line('Swagger: '.$appURL.config('api-documentation.ui.swagger.route'));
            }
            if (config('api-documentation.ui.redoc.enabled', false) === true) {
                $this->line('Redoc: '.$appURL.config('api-documentation.ui.redoc.route'));
            }
        } else {
            $this->comment('You need to enable at least one UI inside "config/api-documentation.php" to view the documentation!');
            $this->comment('To publish the configuration file run: "php artisan vendor:publish --tag=api-documentation-config"');
        }

        return self::SUCCESS;
    }
}
