<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Services\QueryParameterExtractor;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SmartController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class QueryParameterExtractorTest extends TestCase
{
    private QueryParameterExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = app(QueryParameterExtractor::class);
    }

    /** @test */
    public function it_can_extract_query_parameters_from_docblock()
    {
        $params = $this->extractor->extractFromMethod(SmartController::class, 'index');

        expect($params)
            ->toBeArray()
            ->toHaveCount(2)
            ->toHaveKeys(['page', 'per_page'])
            ->and($params['page'])
            ->toHaveKeys(['description', 'required', 'type', 'format'])
            ->and($params['page']['type'])
            ->toBe('integer')
            ->and($params['per_page']['type'])
            ->toBe('integer');
    }

    /** @test */
    public function it_can_extract_query_parameters_with_descriptions()
    {
        $params = $this->extractor->extractFromMethod(SmartController::class, 'paginated');

        expect($params)
            ->toBeArray()
            ->toHaveCount(2)
            ->toHaveKeys(['search', 'status'])
            ->and($params['search'])
            ->toHaveKeys(['description', 'required', 'type', 'format'])
            ->and($params['search']['type'])
            ->toBe('string')
            ->and($params['status']['type'])
            ->toBe('string');
    }

    /** @test */
    public function it_returns_empty_array_for_non_existent_class()
    {
        $params = $this->extractor->extractFromMethod('NonExistentClass', 'nonExistentMethod');

        expect($params)->toBeArray()->toBeEmpty();
    }

    /** @test */
    public function it_maps_php_types_to_openapi_types_correctly()
    {
        $reflectionMethod = new \ReflectionMethod($this->extractor, 'mapPhpTypeToOpenApi');
        $reflectionMethod->setAccessible(true);

        expect($reflectionMethod->invoke($this->extractor, 'int'))->toBe('integer')
            ->and($reflectionMethod->invoke($this->extractor, 'float'))->toBe('number')
            ->and($reflectionMethod->invoke($this->extractor, 'bool'))->toBe('boolean')
            ->and($reflectionMethod->invoke($this->extractor, 'array'))->toBe('array')
            ->and($reflectionMethod->invoke($this->extractor, 'object'))->toBe('object')
            ->and($reflectionMethod->invoke($this->extractor, 'string'))->toBe('string')
            ->and($reflectionMethod->invoke($this->extractor, 'custom'))->toBe('string');
    }

    /** @test */
    public function it_can_detect_example_type()
    {
        $reflectionMethod = new \ReflectionMethod($this->extractor, 'detectExampleType');
        $reflectionMethod->setAccessible(true);

        expect($reflectionMethod->invoke($this->extractor, '123'))->toBe('integer')
            ->and($reflectionMethod->invoke($this->extractor, '123.45'))->toBe('number')
            ->and($reflectionMethod->invoke($this->extractor, 'true'))->toBe('boolean')
            ->and($reflectionMethod->invoke($this->extractor, 'some string'))->toBe('string');
    }

    /** @test */
    public function it_can_detect_example_format()
    {
        $reflectionMethod = new \ReflectionMethod($this->extractor, 'detectExampleFormat');
        $reflectionMethod->setAccessible(true);

        expect($reflectionMethod->invoke($this->extractor, 'test@example.com'))->toBe('email')
            ->and($reflectionMethod->invoke($this->extractor, '2021-01-01'))->toBe('date')
            ->and($reflectionMethod->invoke($this->extractor, '2021-01-01T12:00:00Z'))->toBe('date-time')
            ->and($reflectionMethod->invoke($this->extractor, 'normal string'))->toBeNull();
    }
}
