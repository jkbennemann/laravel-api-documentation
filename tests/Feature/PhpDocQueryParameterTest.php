<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\SearchController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class PhpDocQueryParameterTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('api-documentation.routes.prefixes', ['api']);
        config()->set('api-documentation.routes.middleware', []);
    }

    public function test_phpdoc_params_extracted_as_query_parameters(): void
    {
        Route::get('api/search', [SearchController::class, 'search']);

        $spec = $this->generateSpec();
        $params = $spec['paths']['/api/search']['get']['parameters'] ?? [];

        $names = array_column($params, 'name');
        expect($names)->toContain('query')
            ->toContain('limit')
            ->toContain('include_archived');
    }

    public function test_phpdoc_param_types_mapped_correctly(): void
    {
        Route::get('api/search', [SearchController::class, 'search']);

        $spec = $this->generateSpec();
        $params = $spec['paths']['/api/search']['get']['parameters'] ?? [];

        $queryParam = collect($params)->firstWhere('name', 'query');
        $limitParam = collect($params)->firstWhere('name', 'limit');
        $archivedParam = collect($params)->firstWhere('name', 'include_archived');

        expect($queryParam['schema']['type'])->toBe('string');
        expect($limitParam['schema']['type'])->toBe('integer');
        expect($archivedParam['schema']['type'])->toBe('boolean');
    }

    public function test_phpdoc_param_descriptions_preserved(): void
    {
        Route::get('api/search', [SearchController::class, 'search']);

        $spec = $this->generateSpec();
        $params = $spec['paths']['/api/search']['get']['parameters'] ?? [];

        $queryParam = collect($params)->firstWhere('name', 'query');
        expect($queryParam['description'])->toBe('The search query string');
    }

    public function test_phpdoc_params_not_extracted_for_post_routes(): void
    {
        Route::post('api/search', [SearchController::class, 'search']);

        $spec = $this->generateSpec();
        $params = $spec['paths']['/api/search']['post']['parameters'] ?? [];

        // PHPDoc params should not appear as query params on POST routes
        $names = array_column($params, 'name');
        expect($names)->not()->toContain('query');
    }

    public function test_no_phpdoc_params_no_query_parameters(): void
    {
        Route::get('api/no-params', [SearchController::class, 'noParams']);

        $spec = $this->generateSpec();
        $operation = $spec['paths']['/api/no-params']['get'] ?? [];

        // Should not have parameters key (or empty)
        $params = $operation['parameters'] ?? [];
        expect($params)->toBeEmpty();
    }

    public function test_phpdoc_params_are_not_required(): void
    {
        Route::get('api/search', [SearchController::class, 'search']);

        $spec = $this->generateSpec();
        $params = $spec['paths']['/api/search']['get']['parameters'] ?? [];

        foreach ($params as $param) {
            expect($param['required'])->toBeFalse();
        }
    }
}
