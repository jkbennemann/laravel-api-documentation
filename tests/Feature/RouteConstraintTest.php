<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class RouteConstraintTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    private function getPathParameter(array $spec, string $path, string $method, string $paramName): ?array
    {
        $parameters = $spec['paths'][$path][$method]['parameters'] ?? [];

        foreach ($parameters as $param) {
            if ($param['name'] === $paramName && $param['in'] === 'path') {
                return $param;
            }
        }

        return null;
    }

    public function test_numeric_constraint_sets_integer_type(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show'])
            ->where('post', '[0-9]+');

        $spec = $this->generateSpec();

        $param = $this->getPathParameter($spec, '/api/posts/{post}', 'get', 'post');
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('integer');
    }

    public function test_digit_shorthand_constraint_sets_integer_type(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show'])
            ->where('post', '\d+');

        $spec = $this->generateSpec();

        $param = $this->getPathParameter($spec, '/api/posts/{post}', 'get', 'post');
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('integer');
    }

    public function test_alpha_constraint_sets_pattern(): void
    {
        Route::get('api/posts/{slug}', [PostController::class, 'show'])
            ->where('slug', '[a-z0-9-]+');

        $spec = $this->generateSpec();

        $param = $this->getPathParameter($spec, '/api/posts/{slug}', 'get', 'slug');
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema']['pattern'])->toBe('[a-z0-9-]+');
    }

    public function test_custom_regex_constraint_sets_pattern(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show'])
            ->where('post', '[A-Z]{3}-[0-9]{4}');

        $spec = $this->generateSpec();

        $param = $this->getPathParameter($spec, '/api/posts/{post}', 'get', 'post');
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema']['pattern'])->toBe('[A-Z]{3}-[0-9]{4}');
    }

    public function test_no_constraint_defaults_to_string(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show']);

        $spec = $this->generateSpec();

        $param = $this->getPathParameter($spec, '/api/posts/{post}', 'get', 'post');
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema'])->not()->toHaveKey('pattern');
    }

    public function test_uuid_constraint_sets_uuid_format(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show'])
            ->where('post', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

        $spec = $this->generateSpec();

        $param = $this->getPathParameter($spec, '/api/posts/{post}', 'get', 'post');
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema']['format'])->toBe('uuid');
    }

    public function test_where_alpha_numeric_sets_pattern(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show'])
            ->whereAlphaNumeric('post');

        $spec = $this->generateSpec();

        $param = $this->getPathParameter($spec, '/api/posts/{post}', 'get', 'post');
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('string');
        expect($param['schema'])->toHaveKey('pattern');
    }

    public function test_where_number_sets_integer_type(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show'])
            ->whereNumber('post');

        $spec = $this->generateSpec();

        $param = $this->getPathParameter($spec, '/api/posts/{post}', 'get', 'post');
        expect($param)->not()->toBeNull();
        expect($param['schema']['type'])->toBe('integer');
    }
}
