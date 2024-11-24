<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Http\Controllers;

class RedocController
{
    public function index()
    {
        $filename = config('api-documentation.ui.storage.filename', 'api-documentation.json');

        return view('api-documentation::redoc.index', [
            'redocVersion' => config('api-documentation.ui.redoc.version', '3.0.0'),
            'documentationFile' => asset($filename),
        ]);
    }
}
