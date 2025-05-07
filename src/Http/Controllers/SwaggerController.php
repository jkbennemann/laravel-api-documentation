<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Http\Controllers;

use JkBennemann\LaravelApiDocumentation\Traits\FileVisibilityTrait;

class SwaggerController
{
    use FileVisibilityTrait;

    public function index()
    {
        $filename = config('api-documentation.ui.storage.filename', null);
        $oldFile = $filename ? asset($filename) : null;

        $files = [];
        foreach (config('api-documentation.ui.storage.files', []) as $key => $file) {
            if ($this->check($key)) {
                $files[] = [
                    'name' => $file['name'] ?? $file['filename'],
                    'filename' => asset($file['filename']),
                ];
            }
        }

        return view('api-documentation::swagger.index', [
            'swaggerVersion' => config('api-documentation.ui.swagger.version', '3.0.0'),
            'openApiVersion' => config('api-documentation.open_api_version', '3.0.0'),
            'documentationFile' => $oldFile,
            'documentationFiles' => $files,
        ]);
    }
}
