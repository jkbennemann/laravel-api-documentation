<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Single-action invokable controller — no attributes, no annotations.
 * Tests inline JSON response analysis.
 */
class StatusController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => '1.0.0',
        ]);
    }
}
