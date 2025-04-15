<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Http\Controllers;

class RedocController
{
    public function index()
    {
        $filename = config('api-documentation.ui.storage.filename', null);
        $oldFile = $filename ? asset($filename) : null;

        $files = [];
        foreach (config('api-documentation.ui.storage.files', []) as $file) {
            $files[] = [
                'name' => $file['name'] ?? $file['filename'],
                'filename' => asset($file['filename']),
            ];
        }

        return view('api-documentation::redoc.index', [
            'redocVersion' => config('api-documentation.ui.redoc.version', '3.0.0'),
            'documentationFile' => $oldFile,
            'documentationFiles' => $files,
        ]);
    }
}
