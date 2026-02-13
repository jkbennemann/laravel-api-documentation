<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'id', required: true, type: 'string', description: 'Unique box identifier')]
#[Parameter(name: 'title', required: true, type: 'string', description: 'Box name')]
#[Parameter(name: 'domain', required: true, type: 'string', description: 'Primary domain')]
#[Parameter(name: 'created_at', type: 'string', format: 'date-time', description: 'Creation timestamp')]
class BoxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'domain' => $this->domain,
            'created_at' => $this->created_at,
        ];
    }
}
