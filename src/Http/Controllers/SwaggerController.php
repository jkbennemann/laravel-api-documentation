<?php

declare(strict_types=1);

namespace Bennemann\LaravelApiDocumentation\Http\Controllers;

class SwaggerController
{
    public function index()
    {
        return view('api-documentation::swagger.index', [
            'swaggerVersion' => config('api-documentation.ui.swagger.version', '3.0.0'),
            'openApiVersion' => config('api-documentation.open_api_version', '3.0.0'),
            'documentationFile' => asset('storage/api-documentation.json'),
        ]);
    }
}
