<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\PaginatedUserResource;

class ExplicitNonCollectionController
{
    #[DataResponse(status: 200, resource: PaginatedUserResource::class, description: 'Single item')]
    public function show(): JsonResource
    {
        return new PaginatedUserResource(null);
    }
}
