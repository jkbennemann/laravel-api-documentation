<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'street', type: 'string', description: 'Street address', example: '123 Main St')]
#[Parameter(name: 'city', type: 'string', description: 'City name', example: 'Berlin')]
class NestedDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'street' => $this->street,
            'city' => $this->city,
        ];
    }
}
