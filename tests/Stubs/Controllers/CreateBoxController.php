<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;

class CreateBoxController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['box' => []], 201);
    }
}
