<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\ExcludeFromDocs;

#[ExcludeFromDocs]
class ExcludedController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['id' => $id]);
    }
}
