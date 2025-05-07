<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\NestedData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\DataResource;

class DtoController extends Controller
{
    public function nestedSimple(): NestedData
    {
        return $this->dto();
    }

    public function nestedResource(): DataResource
    {
        return new DataResource($this->dto());
    }

    public function nestedResourceCollection(): AnonymousResourceCollection
    {
        $data = collect([
            $this->dto(),
            $this->dto(),
        ]);

        return DataResource::collection($data);
    }

    public function nestedJsonData(): JsonResponse
    {
        return response()->json($this->dto());
    }

    private function dto(): NestedData
    {
        return NestedData::from([
            'id' => '123',
            'age' => 42,
            'items' => [
                [
                    'id' => '456',
                    'age' => 30,
                    'isActive' => true,
                ],
                [
                    'id' => '567',
                    'age' => 30,
                    'isActive' => false,
                ],
            ],
        ]);
    }
}
