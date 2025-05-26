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
        $oldFile = $filename ? asset($filename) : null;

        return view('api-documentation::scalar.index', [
            'documentationFile' => $oldFile,
        ]);
    }
}
