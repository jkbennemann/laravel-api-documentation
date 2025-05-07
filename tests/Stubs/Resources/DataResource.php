<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\NestedData;

class DataResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var NestedData $resource */
        $resource = $this->resource;

        return [
            'identifier' => $resource->id,
            'current_age' => $resource->age,
            'response_items' => $resource->items,
        ];
    }
}
