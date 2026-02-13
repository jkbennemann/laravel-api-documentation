<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;

class PhpDocController
{
    /**
     * Retrieve a list of all active users in the system.
     *
     * This endpoint returns paginated results with filtering support.
     */
    public function index(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * @see https://example.com/docs
     */
    public function noDescription(): JsonResponse
    {
        return response()->json([]);
    }

    public function noPhpDoc(): JsonResponse
    {
        return response()->json([]);
    }
}
