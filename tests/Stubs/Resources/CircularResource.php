<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'id', type: 'integer', description: 'Resource ID', example: 1)]
#[Parameter(name: 'parent', type: 'object', description: 'Parent resource (circular)', nullable: true, resource: CircularResource::class)]
class CircularResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'parent' => $this->parent,
        ];
    }
}
