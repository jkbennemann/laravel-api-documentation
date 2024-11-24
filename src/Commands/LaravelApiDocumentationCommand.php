<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use openapiphp\openapi\Writer;
use Throwable;

class LaravelApiDocumentationCommand extends Command
{
    public $signature = 'documentation:generate';

    public $description = 'Documentation generator for Laravel API';

    public function handle(RouteComposition $routeService, OpenApi $openApiService): int
    {
        $this->comment('Generating documentation...');

        $routesData = $routeService->process();

        $this->line(count($routesData).' routes generated for documentation');

        try {
            $json = Writer::writeToJson($openApiService->processRoutes($routesData)->get());

            $path = $this->getPath();

            $success = File::put($path, $json);

            if ($success === false) {
                $this->error('Error writing documentation to file: Could not write to file.');

                return self::FAILURE;
            }

        } catch (Throwable $e) {
            $this->error('Error writing documentation to file: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Generation completed.');
        $this->newLine();

        if (config('api-documentation.ui.swagger.enabled', false) === true ||
            config('api-documentation.ui.redoc.enabled', false) === true) {
            $default = config('api-documentation.ui.default', false);

            $this->line('You can view the documentation at:');
            $this->line('Default Documentation ('.$default.'): '.config('app.url').'/documentation');
        } else {
            $this->comment('You need to enable at least one UI inside "config/api-documentation.php" to view the documentation!');
            $this->comment('To publish the configuration file run: "php artisan vendor:publish --tag=api-documentation-config"');

            return self::SUCCESS;
        }

        if (config('api-documentation.ui.swagger.enabled', false) === true) {
            $this->line('Swagger: '.config('app.url').config('api-documentation.ui.swagger.route'));
        }
        if (config('api-documentation.ui.redoc.enabled', false) === true) {
            $this->line('Redoc: '.config('app.url').config('api-documentation.ui.redoc.route'));
        }

        return self::SUCCESS;
    }

    private function getPath(): string
    {
        $filename = config('api-documentation.ui.storage.filename', 'api-documentation.json');

        if (false === Str::endsWith($filename, '.json')) {
            $filename .= '.json';
        }

        return Storage::disk(config('api-documentation.ui.storage.disk', 'public'))
            ->path($filename);
    }
}
