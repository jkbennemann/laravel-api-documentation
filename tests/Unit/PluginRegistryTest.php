<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

it('registers a plugin and reports it via hasPlugin', function () {
    $registry = new PluginRegistry;

    $plugin = new class implements Plugin
    {
        public function name(): string
        {
            return 'test-plugin';
        }

        public function boot(PluginRegistry $registry): void {}

        public function priority(): int
        {
            return 50;
        }
    };

    $registry->register($plugin);

    expect($registry->hasPlugin('test-plugin'))->toBeTrue()
        ->and($registry->hasPlugin('nonexistent'))->toBeFalse();
});

it('removes plugin from registry when boot throws', function () {
    $registry = new PluginRegistry;

    $plugin = new class implements Plugin
    {
        public function name(): string
        {
            return 'failing-plugin';
        }

        public function boot(PluginRegistry $registry): void
        {
            throw new \RuntimeException('Boot failed');
        }

        public function priority(): int
        {
            return 50;
        }
    };

    $registry->register($plugin);

    expect($registry->hasPlugin('failing-plugin'))->toBeFalse();
});

it('sorts extractors by priority descending', function () {
    $registry = new PluginRegistry;

    $low = new class implements RequestBodyExtractor
    {
        public string $label = 'low';

        public function extract(AnalysisContext $ctx): ?SchemaResult
        {
            return null;
        }
    };

    $high = new class implements RequestBodyExtractor
    {
        public string $label = 'high';

        public function extract(AnalysisContext $ctx): ?SchemaResult
        {
            return null;
        }
    };

    $mid = new class implements RequestBodyExtractor
    {
        public string $label = 'mid';

        public function extract(AnalysisContext $ctx): ?SchemaResult
        {
            return null;
        }
    };

    $registry->addRequestExtractor($low, 10);
    $registry->addRequestExtractor($high, 100);
    $registry->addRequestExtractor($mid, 50);

    $sorted = $registry->getRequestExtractors();

    expect($sorted[0]->label)->toBe('high')
        ->and($sorted[1]->label)->toBe('mid')
        ->and($sorted[2]->label)->toBe('low');
});

it('uses default priority of 0 for unknown items', function () {
    $registry = new PluginRegistry;

    $extractor = new class implements ResponseExtractor
    {
        public function extract(AnalysisContext $ctx): array
        {
            return [];
        }
    };

    // Register without priority (defaults to 50)
    $registry->addResponseExtractor($extractor);

    $sorted = $registry->getResponseExtractors();
    expect($sorted)->toHaveCount(1);
});

it('returns empty arrays when no extractors registered', function () {
    $registry = new PluginRegistry;

    expect($registry->getRequestExtractors())->toBeEmpty()
        ->and($registry->getResponseExtractors())->toBeEmpty()
        ->and($registry->getQueryExtractors())->toBeEmpty()
        ->and($registry->getSecurityDetectors())->toBeEmpty()
        ->and($registry->getOperationTransformers())->toBeEmpty()
        ->and($registry->getExceptionProviders())->toBeEmpty()
        ->and($registry->getPlugins())->toBeEmpty();
});

it('lists all registered plugins', function () {
    $registry = new PluginRegistry;

    $pluginA = new class implements Plugin
    {
        public function name(): string
        {
            return 'plugin-a';
        }

        public function boot(PluginRegistry $registry): void {}

        public function priority(): int
        {
            return 50;
        }
    };

    $pluginB = new class implements Plugin
    {
        public function name(): string
        {
            return 'plugin-b';
        }

        public function boot(PluginRegistry $registry): void {}

        public function priority(): int
        {
            return 50;
        }
    };

    $registry->register($pluginA);
    $registry->register($pluginB);

    $plugins = $registry->getPlugins();
    expect(array_keys($plugins))->toBe(['plugin-a', 'plugin-b']);
});
