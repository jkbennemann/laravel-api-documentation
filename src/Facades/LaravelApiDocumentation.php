<?php

declare(strict_types=1);

namespace Bennemann\LaravelApiDocumentation\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Bennemann\LaravelApiDocumentation\LaravelApiDocumentation
 */
class LaravelApiDocumentation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Bennemann\LaravelApiDocumentation\LaravelApiDocumentation::class;
    }
}
