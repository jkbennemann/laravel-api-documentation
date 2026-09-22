<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\SimpleJsonResource;

/**
 * Actions whose runtime status is decided by Laravel, not by anything in the signature.
 *
 * `ResourceResponse::calculateStatus()` answers 201 when the wrapped model reports
 * `wasRecentlyCreated`, so the return type alone cannot tell you what a create responds with.
 */
class CreateStatusController
{
    /** A definite create: 201. */
    public function store(): SimpleJsonResource
    {
        $user = \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User::create(['name' => 'x']);

        return new SimpleJsonResource($user);
    }

    /** Persists without a static `create`: still 201. */
    public function storeViaSave(): SimpleJsonResource
    {
        $user = new \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User;
        $user->save();

        return new SimpleJsonResource($user);
    }

    /** May or may not create, so it genuinely answers either. */
    public function upsert(): SimpleJsonResource
    {
        $user = \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User::updateOrCreate(['name' => 'x']);

        return new SimpleJsonResource($user);
    }

    /** A POST that persists nothing — an action, not a create. Stays 200. */
    public function recalculate(): SimpleJsonResource
    {
        return new SimpleJsonResource(
            \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User::first()
        );
    }

    /** The same resource on a GET is never a create. */
    public function show(): SimpleJsonResource
    {
        return new SimpleJsonResource(
            \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User::first()
        );
    }
}
