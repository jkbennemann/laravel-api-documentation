<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Resources\UserResource;

class UserController
{
    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }
}
