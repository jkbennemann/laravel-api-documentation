<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Http\Controllers;

use JkBennemann\LaravelApiDocumentation\Traits\FileVisibilityTrait;

class ScalarController
{
    use FileVisibilityTrait;

    public function index()
    {
        $proxyUrl = config('api-documentation.ui.scalar.proxy_url', 'https://proxy.scalar.com');

        $filename = config('api-documentation.ui.storage.filename', null);
        $oldFile = [
            'name' => $filename,
            'url' => $filename ? asset($filename) : null,
        ];
        if ($proxyUrl) {
            $oldFile['proxyUrl'] = $proxyUrl;
        }
        $oldFiles[] = $oldFile;

        $files = [];
        foreach (config('api-documentation.ui.storage.files', []) as $key => $file) {
            if ($this->check($key)) {
                $entry = [
                    'title' => $file['filename'],
                    'url' => asset($file['filename']),
                ];
                if ($proxyUrl) {
                    $entry['proxyUrl'] = $proxyUrl;
                }
                $files[] = $entry;
            }
        }

        return view('api-documentation::scalar.index', [
            'files' => ! empty($files) ? $files : $oldFiles,
        ]);
    }
}
