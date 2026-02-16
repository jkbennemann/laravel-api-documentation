<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\SecuritySchemeDetector;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class ApiKeyAuthPlugin implements Plugin, SecuritySchemeDetector
{
    private string $header;

    private string $schemeName;

    private string $description;

    /** @var string[] */
    private array $middleware;

    /**
     * @param  array{header?: string, scheme_name?: string, description?: string, middleware?: string[]}  $config
     */
    public function __construct(array $config = [])
    {
        $this->header = $config['header'] ?? 'X-API-KEY';
        $this->schemeName = $config['scheme_name'] ?? 'apiKeyAuth';
        $this->description = $config['description'] ?? 'API key passed via request header';
        $this->middleware = $config['middleware'] ?? [
            'auth.apikey',
            'apikey',
            'auth.api-key',
            'auth.api_key',
            'api-key',
            'api_key',
        ];
    }

    public function name(): string
    {
        return 'api-key-auth';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addSecurityDetector($this, 55);
    }

    public function priority(): int
    {
        return 55;
    }

    public function detect(AnalysisContext $ctx): ?array
    {
        foreach ($ctx->route->middleware as $middleware) {
            if (in_array($middleware, $this->middleware, true)) {
                return [
                    'name' => $this->schemeName,
                    'scheme' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => $this->header,
                        'description' => $this->description,
                    ],
                    'scopes' => [],
                ];
            }
        }

        return null;
    }
}
