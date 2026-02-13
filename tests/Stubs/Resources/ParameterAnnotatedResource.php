<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'id', type: 'integer', description: 'Resource ID', example: 42)]
#[Parameter(name: 'name', type: 'string', description: 'Display name', example: 'Acme Corp')]
#[Parameter(name: 'active', type: 'boolean', description: 'Whether the resource is active')]
#[Parameter(name: 'score', type: 'number', format: 'float', description: 'Relevance score', example: 9.5)]
#[Parameter(name: 'notes', type: 'string', description: 'Optional notes', nullable: true)]
class ParameterAnnotatedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'active' => $this->active,
            'score' => $this->score,
            'notes' => $this->notes,
        ];
    }
}
