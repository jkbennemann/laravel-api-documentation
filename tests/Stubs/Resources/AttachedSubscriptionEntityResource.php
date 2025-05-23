<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachedSubscriptionEntityResource extends JsonResource
{
    public function toArray(Request $request)
    {
        return array_map(
            static fn ($entity) => [
                'id' => $entity->hashId->hashId, 
                'type' => $entity->type->value, 
                'title' => $entity->title
            ],
            $this->resource
        );
    }
}
