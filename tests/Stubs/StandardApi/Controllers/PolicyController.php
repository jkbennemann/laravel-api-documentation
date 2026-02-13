<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Post;

class PolicyController
{
    public function update(Post $post): JsonResponse
    {
        $this->authorize('update', $post);

        return response()->json($post);
    }

    public function delete(Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        return response()->json(null, 204);
    }

    public function view(Post $post): JsonResponse
    {
        return response()->json($post);
    }
}
