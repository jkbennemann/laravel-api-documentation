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

    /**
     * Creates a CHILD and returns its PARENT — 200, because the parent was not recently created.
     *
     * The ordinary REST shape for a nested collection: POST a step onto a policy, get the whole
     * policy back. Laravel keys 201 on the model the RETURNED resource wraps, and the policy came
     * from route binding — so a method-wide scan for a create call documents a 201 this endpoint
     * never returns. Confirmed against a live API before the rule was tightened.
     */
    public function addChildReturningParent(
        \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User $parent
    ): SimpleJsonResource {
        \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User::create(['name' => 'child']);

        return new SimpleJsonResource($parent);
    }

    /** A create wrapped in a transaction is still a create. */
    public function storeInsideTransaction(): SimpleJsonResource
    {
        $user = \Illuminate\Support\Facades\DB::transaction(function () {
            return \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User::create(['name' => 'x']);
        });

        return new SimpleJsonResource($user);
    }

    /**
     * Saves a model it did NOT create — an update, so 200.
     *
     * `$incident->forceFill([...])->save()` on a route-bound model is how every acknowledge, snooze
     * and toggle in a real application is written. Counting any `->save()` as a create puts a 201 on
     * all of them: the resource reports `wasRecentlyCreated === false` and Laravel answers 200.
     */
    public function acknowledge(
        \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User $user
    ): SimpleJsonResource {
        $user->save();

        return new SimpleJsonResource($user);
    }

    /** The same resource on a GET is never a create. */
    public function show(): SimpleJsonResource
    {
        return new SimpleJsonResource(
            \JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User::first()
        );
    }
}
