<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'id', type: 'string', description: 'Job identifier', example: 'job_123')]
#[Parameter(name: 'status', type: 'string', description: 'Job status', example: 'complete')]
#[Parameter(name: 'result', type: 'object', description: 'Nested result (null until complete)', nullable: true, resource: NestedDetailResource::class)]
class NestedSchemaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'result' => $this->result,
        ];
    }
}
