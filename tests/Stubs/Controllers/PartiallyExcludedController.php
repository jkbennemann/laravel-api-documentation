<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\ExcludeFromDocs;

class PartiallyExcludedController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    #[ExcludeFromDocs]
    public function secret(): JsonResponse
    {
        return response()->json(['secret' => true]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['id' => $id]);
    }
}
