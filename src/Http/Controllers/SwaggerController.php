<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Http\Controllers;

class SwaggerController
{
    public function index()
    {
        $filename = config('api-documentation.ui.storage.filename', 'api-documentation.json');

        return view('api-documentation::swagger.index', [
            'swaggerVersion' => config('api-documentation.ui.swagger.version', '3.0.0'),
            'openApiVersion' => config('api-documentation.open_api_version', '3.0.0'),
            'documentationFile' => asset($filename),
        ]);
    }
}
