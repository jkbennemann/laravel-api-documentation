<?php

namespace Tests\Stubs\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->resource['id'] ?? $this->id,
            'name' => $this->resource['name'] ?? $this->name,
        ];
    }
}
