<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\RegisterRequest;

class RegisterController
{
    public function store(RegisterRequest $request): JsonResponse
    {
        return response()->json(['user' => []], 201);
    }
}
