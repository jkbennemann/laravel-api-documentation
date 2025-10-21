<?php

namespace JkBennemann\LaravelApiDocumentation\Traits;

trait FileVisibilityTrait
{
    public function check(string $key)
    {
        $host = request()->host();

        $defaultFileHost = config('api-documentation.domains.default.main');
        $explicitFileHost = config("api-documentation.domains.{$key}.main");

        $replacedDefaultHost = str_replace(['http://', 'https://'], '', $defaultFileHost);
        $replacedExplicitHost = str_replace(['http://', 'https://'], '', $explicitFileHost);

        return $replacedExplicitHost === $host || $replacedDefaultHost === $host;
    }

    /**
     * Get the default UI for the current request's domain
     */
    public function getDefaultUiForCurrentDomain(): string
    {
        $host = request()->host();

        // Check each domain to find which one matches the current host
        foreach (config('api-documentation.domains', []) as $key => $domain) {
            if ($key === 'default') {
                continue;
            }

            $domainHost = $domain['main'] ?? null;
            if ($domainHost) {
                $cleanHost = str_replace(['http://', 'https://'], '', $domainHost);
                if ($cleanHost === $host) {
                    // Found matching domain, return its default UI or fall back to global
                    return $domain['default_ui'] ?? config('api-documentation.ui.default', 'swagger');
                }
            }
        }

        // No specific domain matched, use global default
        return config('api-documentation.ui.default', 'swagger');
    }
}
