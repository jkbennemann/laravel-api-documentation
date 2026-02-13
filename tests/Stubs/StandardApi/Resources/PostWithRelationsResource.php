<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Post;

/**
 * @mixin Post
 */
class PostWithRelationsResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'user' => $this->whenLoaded('user'),
            'created_at' => $this->created_at,
        ];
    }
}
