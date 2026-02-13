<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Requests\UploadDocumentRequest;

class DocumentController
{
    public function store(UploadDocumentRequest $request): JsonResponse
    {
        return response()->json(['id' => 1], 201);
    }
}
