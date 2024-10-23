<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SampleResource2 extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'foo' => 'bar',
            'email' => 'test@example.com',
        ];
    }
}
