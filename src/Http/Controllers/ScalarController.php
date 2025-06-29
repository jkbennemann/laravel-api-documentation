<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Http\Controllers;

use JkBennemann\LaravelApiDocumentation\Traits\FileVisibilityTrait;

class ScalarController
{
    use FileVisibilityTrait;

    public function index()
    {
        $filename = config('api-documentation.ui.storage.filename', null);
        $oldFile[] = [
            'name' => $filename,
            'url' => $filename ? asset($filename) : null,
            'proxyUrl' => 'https://proxy.scalar.com',
        ];

        $files = [];
        foreach (config('api-documentation.ui.storage.files', []) as $key => $file) {
            if ($this->check($key)) {
                $files[] = [
                    'title' => $file['filename'],
                    'url' => asset($file['filename']),
                    'proxyUrl' => 'https://proxy.scalar.com',
                ];
            }
        }

        return view('api-documentation::scalar.index', [
            'files' => ! empty($files) ? $files : $oldFile,
        ]);
    }
}
