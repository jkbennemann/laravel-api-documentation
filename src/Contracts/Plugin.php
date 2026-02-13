<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Contracts;

use JkBennemann\LaravelApiDocumentation\PluginRegistry;

interface Plugin
{
    public function name(): string;

    public function boot(PluginRegistry $registry): void;

    /**
     * Higher priority plugins run first.
     * Core analyzers use 100-60, plugins should use 50 and below.
     */
    public function priority(): int;
}
