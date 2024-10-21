<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JkBennemann\LaravelApiDocumentation\LaravelApiDocumentation
 */
class LaravelApiDocumentation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JkBennemann\LaravelApiDocumentation\LaravelApiDocumentation::class;
    }
}
