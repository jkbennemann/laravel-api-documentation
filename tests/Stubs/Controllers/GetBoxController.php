<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;

class GetBoxController
{
    public function __invoke(string $boxId): JsonResponse
    {
        return response()->json(['box' => []]);
    }
}
