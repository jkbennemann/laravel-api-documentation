<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\SecuritySchemeDetector;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class BearerAuthPlugin implements Plugin, SecuritySchemeDetector
{
    private const AUTH_MIDDLEWARE_MAP = [
        'auth:sanctum' => 'bearerAuth',
        'auth:api' => 'bearerAuth',
        'jwt.auth' => 'bearerAuth',
        'jwt.verify' => 'bearerAuth',
        'auth' => 'bearerAuth',
    ];

    public function name(): string
    {
        return 'bearer-auth';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addSecurityDetector($this, 50);
    }

    public function priority(): int
    {
        return 50;
    }

    public function detect(AnalysisContext $ctx): ?array
    {
        $hasAuth = false;
        $scopes = [];

        foreach ($ctx->route->middleware as $middleware) {
            // Match auth:* patterns
            if (str_starts_with($middleware, 'auth:') || isset(self::AUTH_MIDDLEWARE_MAP[$middleware])) {
                $hasAuth = true;
            }

            // Extract scopes from scope/scopes middleware (Passport)
            if (str_starts_with($middleware, 'scope:') || str_starts_with($middleware, 'scopes:')) {
                $scopeString = substr($middleware, strpos($middleware, ':') + 1);
                $scopes = array_merge($scopes, array_filter(explode(',', $scopeString)));
            }

            // Extract abilities from ability/abilities middleware (Sanctum)
            if (str_starts_with($middleware, 'ability:') || str_starts_with($middleware, 'abilities:')) {
                $abilityString = substr($middleware, strpos($middleware, ':') + 1);
                $scopes = array_merge($scopes, array_filter(explode(',', $abilityString)));
            }
        }

        if (! $hasAuth) {
            return null;
        }

        return [
            'name' => 'bearerAuth',
            'scheme' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT',
            ],
            'scopes' => array_values(array_unique($scopes)),
        ];
    }
}
