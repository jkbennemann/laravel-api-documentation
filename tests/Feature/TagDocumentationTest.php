<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\TagDescriptionController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class TagDocumentationTest extends TestCase
{
    private function generateSpec(array $configOverrides = []): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        $config = array_merge([
            'open_api_version' => '3.1.0',
            'version' => '1.0.0',
            'title' => 'Test API',
            'tags' => config('api-documentation.tags', []),
            'tag_groups' => config('api-documentation.tag_groups', []),
            'trait_tags' => config('api-documentation.trait_tags', []),
            'external_docs' => config('api-documentation.external_docs'),
        ], $configOverrides);

        return $emitter->emit($contexts, $config);
    }

    public function test_tag_description_from_config(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $spec = $this->generateSpec([
            'tags' => ['Widgets' => 'Configured widget description'],
        ]);

        $tag = collect($spec['tags'])->firstWhere('name', 'Widgets');
        expect($tag)->not()->toBeNull();
        expect($tag['description'])->toBe('Configured widget description');
    }

    public function test_tag_description_from_attribute(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $spec = $this->generateSpec();

        $tag = collect($spec['tags'])->firstWhere('name', 'Widgets');
        expect($tag)->not()->toBeNull();
        expect($tag['description'])->toBe('Widget management operations');
    }

    public function test_config_overrides_attribute_description(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $spec = $this->generateSpec([
            'tags' => ['Widgets' => 'Config wins over attribute'],
        ]);

        $tag = collect($spec['tags'])->firstWhere('name', 'Widgets');
        expect($tag)->not()->toBeNull();
        expect($tag['description'])->toBe('Config wins over attribute');
    }

    public function test_tag_without_description_has_no_description_key(): void
    {
        Route::get('api/simple/{id}', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec();

        $tag = collect($spec['tags'])->firstWhere('name', 'Simple');
        expect($tag)->not()->toBeNull();
        expect($tag)->not()->toHaveKey('description');
    }

    public function test_x_tag_groups_from_config(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $groups = [
            ['name' => 'Product Management', 'tags' => ['Widgets']],
        ];

        $spec = $this->generateSpec([
            'tag_groups' => $groups,
        ]);

        expect($spec)->toHaveKey('x-tagGroups');
        expect($spec['x-tagGroups'])->toBe($groups);
    }

    public function test_x_tag_groups_adds_other_group_for_ungrouped_tags(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);
        Route::get('api/simple', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec([
            'tag_groups' => [
                ['name' => 'Product Management', 'tags' => ['Widgets']],
            ],
        ]);

        expect($spec['x-tagGroups'])->toHaveCount(2);
        expect($spec['x-tagGroups'][0]['name'])->toBe('Product Management');
        expect($spec['x-tagGroups'][1]['name'])->toBe('Other');
        expect($spec['x-tagGroups'][1]['tags'])->toContain('Simple');
    }

    public function test_x_tag_groups_ungrouped_tags_omitted_when_disabled(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);
        Route::get('api/simple', [SimpleController::class, 'simple']);

        $spec = $this->generateSpec([
            'tag_groups' => [
                ['name' => 'Product Management', 'tags' => ['Widgets']],
            ],
            'tag_groups_include_ungrouped' => false,
        ]);

        expect($spec['x-tagGroups'])->toHaveCount(1);
        expect($spec['x-tagGroups'][0]['name'])->toBe('Product Management');
    }

    public function test_x_tag_groups_no_other_group_when_all_tags_grouped(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $spec = $this->generateSpec([
            'tag_groups' => [
                ['name' => 'All', 'tags' => ['Widgets']],
            ],
        ]);

        expect($spec['x-tagGroups'])->toHaveCount(1);
        expect($spec['x-tagGroups'][0]['name'])->toBe('All');
    }

    public function test_x_tag_groups_absent_when_not_configured(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $spec = $this->generateSpec();

        expect($spec)->not()->toHaveKey('x-tagGroups');
    }

    public function test_trait_tags_from_config(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $spec = $this->generateSpec([
            'trait_tags' => [
                ['name' => 'Getting Started', 'description' => '# Welcome'],
            ],
        ]);

        $tag = collect($spec['tags'])->firstWhere('name', 'Getting Started');
        expect($tag)->not()->toBeNull();
        expect($tag['x-traitTag'])->toBeTrue();
        expect($tag['description'])->toBe('# Welcome');
    }

    public function test_external_docs_from_config(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $spec = $this->generateSpec([
            'external_docs' => [
                'url' => 'https://docs.example.com',
                'description' => 'Full documentation',
            ],
        ]);

        expect($spec)->toHaveKey('externalDocs');
        expect($spec['externalDocs']['url'])->toBe('https://docs.example.com');
        expect($spec['externalDocs']['description'])->toBe('Full documentation');
    }

    public function test_external_docs_absent_when_not_configured(): void
    {
        Route::get('api/widgets', [TagDescriptionController::class, 'index']);

        $spec = $this->generateSpec();

        expect($spec)->not()->toHaveKey('externalDocs');
    }
}
