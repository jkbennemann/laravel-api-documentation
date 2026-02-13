<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Resources\UserWithPostsResource;

class UserRelationsController
{
    public function show(User $user): UserWithPostsResource
    {
        return new UserWithPostsResource($user->load('posts'));
    }
}
