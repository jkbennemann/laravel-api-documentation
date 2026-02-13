<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\PhpDocController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class PhpDocDescriptionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_phpdoc_description_used_when_no_attribute(): void
    {
        Route::get('api/users', [PhpDocController::class, 'index']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/users']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->toHaveKey('description');
        expect($operation['description'])->toContain('active users');
    }

    public function test_attribute_description_takes_precedence(): void
    {
        Route::get('api/described', [SimpleController::class, 'description']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/described']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation['description'])->toBe('My Description');
    }

    public function test_no_phpdoc_no_description(): void
    {
        Route::get('api/nodoc', [PhpDocController::class, 'noPhpDoc']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/nodoc']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->not()->toHaveKey('description');
    }

    public function test_phpdoc_with_only_tags_no_description(): void
    {
        Route::get('api/tagsonly', [PhpDocController::class, 'noDescription']);

        $spec = $this->generateSpec();

        $operation = $spec['paths']['/api/tagsonly']['get'] ?? null;
        expect($operation)->not()->toBeNull();
        expect($operation)->not()->toHaveKey('description');
    }
}
