<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Resources\UserWithAppendsResource;

class UserAppendsController
{
    public function show(User $user): UserWithAppendsResource
    {
        return new UserWithAppendsResource($user);
    }
}
