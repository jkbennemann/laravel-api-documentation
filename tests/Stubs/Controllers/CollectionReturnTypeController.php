<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\PaginatedUserResource;

class CollectionReturnTypeController
{
    #[DataResponse(status: 200, resource: PaginatedUserResource::class, description: 'List items')]
    public function index(): AnonymousResourceCollection
    {
        return PaginatedUserResource::collection([]);
    }
}
