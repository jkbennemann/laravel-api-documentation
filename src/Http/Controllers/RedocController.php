<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Http\Controllers;

class RedocController
{
    public function index()
    {
        return view('api-documentation::redoc.index', [
            'redocVersion' => config('api-documentation.ui.redoc.version', '3.0.0'),
            'documentationFile' => asset('storage/api-documentation.json'),
        ]);
    }
}
