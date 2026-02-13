<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\DocumentationFile;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Requests\CreateBoxRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Resources\BoxResource;

#[DocumentationFile('public-api')]
class BoxController extends Controller
{
    #[Tag('Box')]
    #[Summary('Get box details')]
    #[Description('Retrieve detailed information about a specific box')]
    #[DataResponse(200, resource: BoxResource::class, description: 'Box details')]
    public function show(string $boxId): JsonResponse
    {
        return response()->json([]);
    }

    #[Tag('Box')]
    #[Summary('Create a new box')]
    #[Description('Provision a new box with the given configuration')]
    #[DataResponse(202, resource: [
        'id' => 'string',
        'status' => 'string',
        'message' => 'string',
    ], description: 'Box creation started')]
    public function store(CreateBoxRequest $request): JsonResponse
    {
        return response()->json([], 202);
    }

    #[Tag('Box')]
    #[Summary('Delete a box')]
    #[Description('Permanently delete a box and all its data')]
    #[DataResponse(204, description: 'Box deleted')]
    public function destroy(string $boxId): JsonResponse
    {
        return response()->json([], 204);
    }
}
