<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;

class DeprecatedController
{
    /**
     * @deprecated Use v2/status instead
     */
    public function legacyStatus(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function currentStatus(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
