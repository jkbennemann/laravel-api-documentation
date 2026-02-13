<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Post;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Resources\PostWithRelationsResource;

class PostRelationsController
{
    public function show(Post $post): PostWithRelationsResource
    {
        return new PostWithRelationsResource($post->load('user'));
    }
}
