<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation;

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;

class LaravelApiDocumentation
{
    /**
     * Programmatically register a plugin at runtime.
     */
    public static function extend(Plugin $plugin): void
    {
        $registry = app(PluginRegistry::class);
        $registry->register($plugin);
    }

    /**
     * Get the plugin registry instance.
     */
    public static function plugins(): PluginRegistry
    {
        return app(PluginRegistry::class);
    }
}
