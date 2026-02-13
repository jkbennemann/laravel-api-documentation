<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\AnnotatedErrorResource;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\NestedSchemaResource;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\ParameterAnnotatedResource;

class AnnotatedResourceController extends Controller
{
    #[DataResponse(status: 200, resource: ParameterAnnotatedResource::class, description: 'Success')]
    public function annotatedSuccess(): JsonResponse
    {
        return response()->json([]);
    }

    #[DataResponse(status: 200, resource: ParameterAnnotatedResource::class, description: 'Success')]
    #[DataResponse(status: 404, resource: AnnotatedErrorResource::class, description: 'Not found')]
    public function withErrorResponse(): JsonResponse
    {
        return response()->json([]);
    }

    #[DataResponse(status: 200, resource: NestedSchemaResource::class, description: 'Job status')]
    public function nestedResource(): JsonResponse
    {
        return response()->json([]);
    }
}
