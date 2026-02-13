<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation;

use JkBennemann\LaravelApiDocumentation\Contracts\ExceptionSchemaProvider;
use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Contracts\SecuritySchemeDetector;

class PluginRegistry
{
    /** @var Plugin[] */
    private array $plugins = [];

    /** @var RequestBodyExtractor[] */
    private array $requestExtractors = [];

    /** @var ResponseExtractor[] */
    private array $responseExtractors = [];

    /** @var QueryParameterExtractor[] */
    private array $queryExtractors = [];

    /** @var SecuritySchemeDetector[] */
    private array $securityDetectors = [];

    /** @var OperationTransformer[] */
    private array $operationTransformers = [];

    /** @var ExceptionSchemaProvider[] */
    private array $exceptionProviders = [];

    /** @var \WeakMap<object, int> Priority map for sorting (avoids spl_object_id recycling) */
    private \WeakMap $priorities;

    public function __construct()
    {
        $this->priorities = new \WeakMap;
    }

    public function register(Plugin $plugin): void
    {
        $name = $plugin->name();
        $this->plugins[$name] = $plugin;

        try {
            $plugin->boot($this);
        } catch (\Throwable $e) {
            unset($this->plugins[$name]);
            if (function_exists('logger')) {
                logger()->error("API Documentation plugin '{$name}' failed to boot: {$e->getMessage()}");
            }
        }
    }

    public function addRequestExtractor(RequestBodyExtractor $extractor, int $priority = 50): void
    {
        $this->requestExtractors[] = $extractor;
        $this->priorities[$extractor] = $priority;
    }

    public function addResponseExtractor(ResponseExtractor $extractor, int $priority = 50): void
    {
        $this->responseExtractors[] = $extractor;
        $this->priorities[$extractor] = $priority;
    }

    public function addQueryExtractor(QueryParameterExtractor $extractor, int $priority = 50): void
    {
        $this->queryExtractors[] = $extractor;
        $this->priorities[$extractor] = $priority;
    }

    public function addSecurityDetector(SecuritySchemeDetector $detector, int $priority = 50): void
    {
        $this->securityDetectors[] = $detector;
        $this->priorities[$detector] = $priority;
    }

    public function addOperationTransformer(OperationTransformer $transformer, int $priority = 50): void
    {
        $this->operationTransformers[] = $transformer;
        $this->priorities[$transformer] = $priority;
    }

    public function addExceptionProvider(ExceptionSchemaProvider $provider): void
    {
        $this->exceptionProviders[] = $provider;
    }

    /**
     * @return RequestBodyExtractor[]
     */
    public function getRequestExtractors(): array
    {
        return $this->sortByPriority($this->requestExtractors);
    }

    /**
     * @return ResponseExtractor[]
     */
    public function getResponseExtractors(): array
    {
        return $this->sortByPriority($this->responseExtractors);
    }

    /**
     * @return QueryParameterExtractor[]
     */
    public function getQueryExtractors(): array
    {
        return $this->sortByPriority($this->queryExtractors);
    }

    /**
     * @return SecuritySchemeDetector[]
     */
    public function getSecurityDetectors(): array
    {
        return $this->sortByPriority($this->securityDetectors);
    }

    /**
     * @return OperationTransformer[]
     */
    public function getOperationTransformers(): array
    {
        return $this->sortByPriority($this->operationTransformers);
    }

    /**
     * @return ExceptionSchemaProvider[]
     */
    public function getExceptionProviders(): array
    {
        return $this->exceptionProviders;
    }

    public function getExceptionProvider(string $exceptionClass): ?ExceptionSchemaProvider
    {
        foreach ($this->exceptionProviders as $provider) {
            if ($provider->provides($exceptionClass)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return Plugin[]
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function hasPlugin(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /**
     * Sort items by priority (higher priority first).
     *
     * @template T of object
     *
     * @param  T[]  $items
     * @return T[]
     */
    private function sortByPriority(array $items): array
    {
        usort($items, function (object $a, object $b) {
            $priorityA = $this->priorities[$a] ?? 0;
            $priorityB = $this->priorities[$b] ?? 0;

            return $priorityB <=> $priorityA;
        });

        return $items;
    }
}
