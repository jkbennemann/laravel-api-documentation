<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Post;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Requests\StorePostRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Requests\UpdatePostRequest;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Resources\PostResource;

/**
 * Standard apiResource CRUD controller — no attributes, no annotations.
 * This is what `php artisan make:controller PostController --api` produces.
 */
class PostController
{
    /**
     * Display a listing of the resource.
     */
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $posts = Post::paginate(15);

        return PostResource::collection($posts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        $post = Post::create($request->validated());

        return (new PostResource($post))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Post $post): PostResource
    {
        return new PostResource($post);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePostRequest $request, Post $post): PostResource
    {
        $post->update($request->validated());

        return new PostResource($post);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Post $post): \Illuminate\Http\Response
    {
        $post->delete();

        return response()->noContent();
    }
}
