<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SampleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'test@example.com',
        ];
    }
}
