<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Post;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Resources\PostResource;

class HeaderController
{
    public function withSingleHeader(Post $post): \Illuminate\Http\Response
    {
        return (new PostResource($post))
            ->response()
            ->header('X-Request-Id', 'abc-123');
    }

    public function withMultipleHeaders(Post $post): \Illuminate\Http\Response
    {
        return (new PostResource($post))
            ->response()
            ->withHeaders([
                'X-Request-Id' => 'abc-123',
                'X-Rate-Limit-Remaining' => '99',
            ]);
    }

    public function withNoHeaders(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
