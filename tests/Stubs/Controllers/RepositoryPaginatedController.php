<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\PaginatedUserResource;

class RepositoryPaginatedController
{
    #[DataResponse(status: 200, resource: PaginatedUserResource::class, description: 'List items')]
    public function index(): JsonResource
    {
        return PaginatedUserResource::collection(
            $this->findAllPaginated(15)
        );
    }

    private function findAllPaginated(int $perPage): array
    {
        return [];
    }
}
