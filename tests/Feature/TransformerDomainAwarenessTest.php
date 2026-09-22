<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * An operation transformer can tell which document it is building.
 *
 * Multi-document setups publish the same endpoint into several references, and it does not always
 * mean the same thing in each: an endpoint reachable with one document's credential can be refused
 * outright with another's. Without the document key a transformer had to annotate every document
 * identically or none, so the difference went unwritten.
 */
class TransformerDomainAwarenessTest extends TestCase
{
    public function test_a_transformer_is_told_which_document_it_is_building(): void
    {
        app(SchemaRegistry::class)->reset();

        $transformer = new class implements OperationTransformer
        {
            public array $seen = [];

            public function transform(array $operation, AnalysisContext $ctx): array
            {
                $this->seen[] = $ctx->metadata['domain'] ?? null;
                $operation['x-document'] = $ctx->metadata['domain'] ?? null;

                return $operation;
            }
        };

        app(PluginRegistry::class)->addOperationTransformer($transformer, 10);

        Route::get('api/thing', [SimpleController::class, 'simple']);
        $contexts = app(RouteDiscovery::class)->discover();

        $spec = app(OpenApiEmitter::class)->emit($contexts, ['domain' => 'reseller']);

        $this->assertSame('reseller', $spec['paths']['/api/thing']['get']['x-document'] ?? null);
        $this->assertContains('reseller', $transformer->seen);
    }

    public function test_a_document_key_is_optional(): void
    {
        app(SchemaRegistry::class)->reset();

        $transformer = new class implements OperationTransformer
        {
            public function transform(array $operation, AnalysisContext $ctx): array
            {
                $operation['x-document'] = $ctx->metadata['domain'] ?? 'none';

                return $operation;
            }
        };

        app(PluginRegistry::class)->addOperationTransformer($transformer, 10);

        Route::get('api/thing', [SimpleController::class, 'simple']);
        $contexts = app(RouteDiscovery::class)->discover();

        $spec = app(OpenApiEmitter::class)->emit($contexts, []);

        $this->assertSame('none', $spec['paths']['/api/thing']['get']['x-document'] ?? null);
    }
}
