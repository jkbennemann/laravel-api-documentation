<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Requests\ConditionalRequest;

class ConditionalController
{
    public function store(ConditionalRequest $request): JsonResponse
    {
        return response()->json(['status' => 'created'], 201);
    }
}
