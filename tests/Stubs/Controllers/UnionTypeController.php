<?php

namespace Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use Tests\Stubs\Resources\TestResource;

class UnionTypeController
{
    #[DataResponse(200, description: 'Success response', resource: TestResource::class)]
    #[DataResponse(204, description: 'No content response')]
    #[Tag('Union Types')]
    public function unionReturnType(): TestResource|Response
    {
        // This method has a union return type: TestResource|Response
        if (rand(0, 1)) {
            return new TestResource(['id' => 1, 'name' => 'Test']);
        }

        return response()->noContent();
    }

    #[DataResponse(200, description: 'JSON response', resource: [])]
    #[Tag('Union Types')]
    public function jsonUnionType(): JsonResponse|Response
    {
        // This method has a union return type: JsonResponse|Response
        if (rand(0, 1)) {
            return response()->json(['success' => true]);
        }

        return response()->noContent();
    }

    #[DataResponse(200, description: 'Multiple types', resource: TestResource::class)]
    #[Tag('Union Types')]
    public function multipleUnionType(): TestResource|JsonResponse|Response
    {
        // This method has multiple union types
        $random = rand(0, 2);

        if ($random === 0) {
            return new TestResource(['id' => 1, 'name' => 'Test']);
        } elseif ($random === 1) {
            return response()->json(['data' => 'json']);
        }

        return response()->noContent();
    }
}
