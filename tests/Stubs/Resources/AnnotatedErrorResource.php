<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'message', type: 'string', description: 'Human-readable error description', example: 'Not Found')]
#[Parameter(name: 'status', type: 'integer', description: 'HTTP status code', example: 404)]
class AnnotatedErrorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'message' => $this->resource['message'] ?? 'Error',
            'status' => $this->resource['status'] ?? 500,
        ];
    }
}
