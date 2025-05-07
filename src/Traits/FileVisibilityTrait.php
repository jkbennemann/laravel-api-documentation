<?php

namespace JkBennemann\LaravelApiDocumentation\Traits;

trait FileVisibilityTrait
{
    public function check(string $key)
    {
        return config("api-documentation.domains.{$key}.main") === request()->root() ||
            config('api-documentation.domains.default.main') === request()->root();
    }
}
