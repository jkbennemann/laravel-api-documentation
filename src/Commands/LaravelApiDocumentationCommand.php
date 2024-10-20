<?php

declare(strict_types=1);

namespace Bennemann\LaravelApiDocumentation\Commands;

use Bennemann\LaravelApiDocumentation\Services\OpenApi;
use Bennemann\LaravelApiDocumentation\Services\RouteComposition;
use cebe\openapi\Writer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class LaravelApiDocumentationCommand extends Command
{
    public $signature = 'documentation:generate';

    public $description = 'Documentation generator for Laravel API';

    public function handle(RouteComposition $routeService, OpenApi $openApiService): int
    {
        $this->comment('Generating documentation...');

        $routesData = $routeService->process();

        $this->line(count($routesData) . ' routes generated for documentation');

        try {
            $json = Writer::writeToJson($openApiService->setPathsData($routesData)->get());

            Storage::disk('public')->put('api-documentation.json', $json);
        } catch (\Throwable $e) {
            $this->error('Error writing documentation to file: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Generation completed.');
        $this->newLine();
        $this->line('You can view the documentation at:');

        if (true === config('api-documentation.ui.swagger.enabled', false) ||
            true === config('api-documentation.ui.redoc.enabled', false)) {
            $default = config('api-documentation.ui.default', false);
            $this->line('Default Documentation (' . $default . '): ' . config('app.url') . '/documentation');
        }

        if (true === config('api-documentation.ui.swagger.enabled', false)) {
            $this->line('Swagger: ' . config('app.url') . config('api-documentation.ui.swagger.route'));
        }
        if (true === config('api-documentation.ui.redoc.enabled', false)) {
            $this->line('Redoc: ' . config('app.url') . config('api-documentation.ui.redoc.route'));
        }

        return self::SUCCESS;
    }
}
