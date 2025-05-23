<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SmartController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\AdvancedUserData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\SimpleAnnotated;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\UserData;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ResponseAnalyzerTest extends TestCase
{
    private ResponseAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = app(ResponseAnalyzer::class);
    }

    /** @test */
    public function it_can_analyze_simple_data_object()
    {
        $result = $this->analyzer->analyzeSpatieDataObject(SimpleAnnotated::class);

        expect($result)
            ->toBeArray()
            ->toHaveKey('type')
            ->and($result['type'])
            ->toBe('object')
            ->and($result['properties'])
            ->toHaveKeys(['name', 'description', 'age', 'active'])
            ->and($result['properties']['name']['type'])
            ->toBe('string')
            ->and($result['properties']['age']['type'])
            ->toBe('integer')
            ->and($result['properties']['active']['type'])
            ->toBe('boolean')
            ->and($result['required'])
            ->toContain('name', 'age', 'active');
    }

    /** @test */
    public function it_can_analyze_user_data_object()
    {
        $result = $this->analyzer->analyzeSpatieDataObject(UserData::class);

        expect($result)
            ->toBeArray()
            ->toHaveKey('properties')
            ->and($result['properties'])
            ->toHaveKeys(['id', 'name', 'email'])
            ->and($result['properties']['id']['type'])
            ->toBe('integer')
            ->and($result['required'])
            ->toContain('id', 'name', 'email');
    }

    /** @test */
    public function it_can_analyze_advanced_user_data_object()
    {
        $result = $this->analyzer->analyzeSpatieDataObject(AdvancedUserData::class);

        expect($result)
            ->toBeArray()
            ->toHaveKey('properties')
            ->and($result['properties'])
            ->toHaveKeys(['id', 'name', 'email', 'role', 'permissions'])
            ->and($result['properties']['role']['type'])
            ->toBe('string')
            ->and($result['properties']['permissions']['type'])
            ->toBe('array')
            ->and($result['required'])
            ->toContain('id', 'name', 'email', 'role', 'permissions');
    }

    /** @test */
    public function it_returns_empty_array_for_non_existent_class()
    {
        $result = $this->analyzer->analyzeSpatieDataObject('NonExistentClass');

        expect($result)->toBeArray()->toBeEmpty();
    }

    /** @test */
    public function it_maps_php_types_to_openapi_types_correctly()
    {
        $reflectionMethod = new \ReflectionMethod($this->analyzer, 'mapPhpTypeToOpenApi');
        $reflectionMethod->setAccessible(true);

        expect($reflectionMethod->invoke($this->analyzer, 'int'))->toBe('integer')
            ->and($reflectionMethod->invoke($this->analyzer, 'float'))->toBe('number')
            ->and($reflectionMethod->invoke($this->analyzer, 'bool'))->toBe('boolean')
            ->and($reflectionMethod->invoke($this->analyzer, 'array'))->toBe('array')
            ->and($reflectionMethod->invoke($this->analyzer, 'object'))->toBe('object')
            ->and($reflectionMethod->invoke($this->analyzer, 'string'))->toBe('string')
            ->and($reflectionMethod->invoke($this->analyzer, 'custom'))->toBe('string');
    }

    /** @test */
    public function it_can_extract_format_from_types()
    {
        $reflectionMethod = new \ReflectionMethod($this->analyzer, 'getFormatForType');
        $reflectionMethod->setAccessible(true);

        expect($reflectionMethod->invoke($this->analyzer, 'float'))->toBe('float')
            ->and($reflectionMethod->invoke($this->analyzer, 'DateTime'))->toBe('date-time')
            ->and($reflectionMethod->invoke($this->analyzer, 'DateTimeImmutable'))->toBe('date-time')
            ->and($reflectionMethod->invoke($this->analyzer, 'string'))->toBeNull();
    }

    /** @test */
    public function it_can_analyze_controller_method()
    {
        $result = $this->analyzer->analyzeControllerMethod(SmartController::class, 'getUserWithDynamicType');

        expect($result)
            ->toBeArray()
            ->toHaveKey('oneOf')
            ->and($result['oneOf'])
            ->toBeArray()
            ->toHaveCount(2);
    }
}
