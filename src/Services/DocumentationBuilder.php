<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JkBennemann\LaravelApiDocumentation\Exceptions\DocumentationException;
use openapiphp\openapi\Writer;
use Throwable;

class DocumentationBuilder
{
    public function __construct(
        private RouteComposition $routeService,
        private OpenApi $openApiService
    )
    {
    }

    public function build(string $filename, ?string $name = null, ?string $docName = null): iterable
    {
        if (!isset($name)) {
            $name = $filename;
        }

        $this->setSwaggerDetails($docName);

        $routesData = $this->routeService->process($docName);

        yield count($routesData).' routes generated for ' . $name;
        try {
            $openApi = $this->openApiService->processRoutes($routesData)->get();
            $json = Writer::writeToJson($openApi);
            $path = $this->getPath($filename);
            $success = File::put($path, $json);
            if ($success === false) {
                throw new DocumentationException("Could not write to file.");
            }

            yield "Generation for {$name} completed.";
        } catch (Throwable $e) {

            throw new DocumentationException("Error writing documentation to file '{$filename}': {$e->getMessage()}");
        }
    }

    private function getPath(string $filename): string
    {
        if (Str::endsWith($filename, '.json') === false) {
            $filename .= '.json';
        }

        return Storage::disk(config('api-documentation.ui.storage.disk', 'public'))
            ->path($filename);
    }

    private function setSwaggerDetails($docName)
    {
        $prefix = 'api-documentation.domains.' . $docName;

        if (config($prefix)) {
            $this->openApiService->get()->servers = config($prefix . '.servers');
            $this->openApiService->get()->info->title = config($prefix . '.title');
        }
    }
}
