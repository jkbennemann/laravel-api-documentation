<?php

namespace JkBennemann\LaravelApiDocumentation\Traits;

trait FileVisibilityTrait
{
    public function check(string $key)
    {
        $host = request()->host();

        $defaultFileHost = config("api-documentation.domains.default.main");
        $explicitFileHost = config("api-documentation.domains.{$key}.main");

        $replacedDefaultHost = str_replace(['http://', 'https://'], '', $defaultFileHost);
        $replacedExplicitHost = str_replace(['http://', 'https://'], '', $explicitFileHost);

        return $replacedExplicitHost === $host || $replacedDefaultHost === $host;
    }
}
