<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * @deprecated Use V2 API instead
 */
class DeprecatedClassController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['id' => $id]);
    }

    /**
     * @notDeprecated
     */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
