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

        try {
            $docFiles = $this->getDocumentationFiles($this->option('file'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        foreach ($docFiles as $docName => $file) {
            if (!isset($file['process']) || !$file['process']) {
                unset($docFiles[$docName]);
                continue;
            }

            if (!isset($file['filename'])) {
                // Skip files without filename to trigger fallback behavior
                unset($docFiles[$docName]);
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
                if ($this->option('file')) {
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
                $this->info("Using filename: {$filename}");
                $messages = iterator_to_array($builder->build($filename));
                foreach ($messages as $message) {
                    $this->info("  - {$message}");
                }
                $this->newLine();
            } catch (DocumentationException $e) {
                $this->error("Error in fallback generation: " . $e->getMessage());
                return self::FAILURE;
            } catch (\Throwable $e) {
                $this->error("Unexpected error in fallback generation: " . $e->getMessage());
                $this->error("Stack trace: " . $e->getTraceAsString());
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

    private function getDocumentationFiles($specificFile)
    {
        $docFiles = config('api-documentation.ui.storage.files', []);

        if ($specificFile) {
            if (!isset($docFiles[$specificFile])) {
                $this->error("Documentation file '{$specificFile}' is not defined in config.");
                throw new \InvalidArgumentException("Documentation file '{$specificFile}' is not defined in config.");
            }

            $docFiles = [$specificFile => $docFiles[$specificFile]];
        }

        return $docFiles;
    }
}
