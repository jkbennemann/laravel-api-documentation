<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Schema\EloquentModelAnalyzer;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Product;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class CustomCastTest extends TestCase
{
    public function test_custom_cast_resolves_return_type(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        $schema = $analyzer->getPropertyType(Product::class, 'price');
        expect($schema)->not()->toBeNull();
        expect($schema->type)->toBe('number');
    }

    public function test_builtin_cast_still_works(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        $schema = $analyzer->getPropertyType(Product::class, 'created_at');
        expect($schema)->not()->toBeNull();
        expect($schema->type)->toBe('string');
        expect($schema->format)->toBe('date-time');
    }

    public function test_non_cast_property_returns_null(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        $schema = $analyzer->getPropertyType(Product::class, 'name');
        expect($schema)->toBeNull();
    }
}
