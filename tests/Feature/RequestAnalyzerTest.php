<?php

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Config\Repository;
use JkBennemann\LaravelApiDocumentation\Services\RequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\TestFormRequest;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

class RequestAnalyzerTest extends TestCase
{
    private RequestAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->app->make(Repository::class);
        $this->analyzer = new RequestAnalyzer($config);
    }

    /** @test */
    public function it_can_parse_basic_validation_rules()
    {
        // Rule: Write concise, technical PHP code with accurate examples
        $rules = ['required', 'string', 'max:255'];
        
        // Use reflection to access the protected parseValidationRules method
        $reflection = new ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('parseValidationRules');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->analyzer, $rules);
        
        expect($result)
            ->toBeArray()
            ->toHaveKeys(['type', 'required', 'description', 'maxLength'])
            ->and($result['type'])->toBe('string')
            ->and($result['required'])->toBeTrue()
            ->and($result['maxLength'])->toBe(255)
            ->and($result['description'])->toContain('Required')
            ->and($result['description'])->toContain('Must be a string')
            ->and($result['description'])->toContain('Maximum length: 255');
    }

    /** @test */
    public function it_can_parse_numeric_validation_rules()
    {
        // Rule: Keep the code clean and readable
        $rules = ['required', 'integer', 'between:1,100'];
        
        $reflection = new ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('parseValidationRules');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->analyzer, $rules);
        
        expect($result)
            ->toBeArray()
            ->toHaveKeys(['type', 'required', 'description', 'minimum', 'maximum'])
            ->and($result['type'])->toBe('integer')
            ->and($result['required'])->toBeTrue()
            ->and($result['minimum'])->toBe(1)
            ->and($result['maximum'])->toBe(100)
            ->and($result['description'])->toContain('Required')
            ->and($result['description'])->toContain('Must be an integer')
            ->and($result['description'])->toContain('Value between 1 and 100');
    }

    /** @test */
    public function it_can_parse_enum_validation_rules()
    {
        // Rule: Stick to PHP best practices
        $rules = ['required', 'string', 'in:pending,approved,rejected'];
        
        $reflection = new ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('parseValidationRules');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->analyzer, $rules);
        
        expect($result)
            ->toBeArray()
            ->toHaveKeys(['type', 'required', 'description', 'enum', 'example'])
            ->and($result['type'])->toBe('string')
            ->and($result['required'])->toBeTrue()
            ->and($result['enum'])->toBe(['pending', 'approved', 'rejected'])
            ->and($result['example'])->toBe('pending')
            ->and($result['description'])->toContain('Must be one of: pending, approved, rejected');
    }

    /** @test */
    public function it_can_parse_format_validation_rules()
    {
        // Rule: Keep the code modular and easy to understand
        $rules = ['nullable', 'email'];
        
        $reflection = new ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('parseValidationRules');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->analyzer, $rules);
        
        expect($result)
            ->toBeArray()
            ->toHaveKeys(['type', 'required', 'format', 'nullable', 'description', 'example'])
            ->and($result['type'])->toBe('string')
            ->and($result['required'])->toBeFalse()
            ->and($result['nullable'])->toBeTrue()
            ->and($result['format'])->toBe('email')
            ->and($result['example'])->toBe('user@example.com')
            ->and($result['description'])->toContain('Must be a valid email address');
    }

    /** @test */
    public function it_can_parse_array_validation_rules()
    {
        // Rule: Write concise, technical PHP code with accurate examples
        $rules = ['required', 'array', 'min:1', 'max:10'];
        
        $reflection = new ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('parseValidationRules');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->analyzer, $rules);
        
        expect($result)
            ->toBeArray()
            ->toHaveKeys(['type', 'required', 'description', 'items'])
            ->and($result['type'])->toBe('array')
            ->and($result['required'])->toBeTrue()
            ->and($result['items'])->toBeArray()
            ->and($result['items']['type'])->toBe('string')
            ->and($result['description'])->toContain('Must be an array')
            ->and($result['description'])->toContain('Minimum items: 1')
            ->and($result['description'])->toContain('Maximum items: 10');
    }

    /** @test */
    public function it_handles_date_and_datetime_formats()
    {
        // Rule: Keep the code clean and readable
        $dateRules = ['date'];
        $dateTimeRules = ['date_format:Y-m-d H:i:s'];
        
        $reflection = new ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('parseValidationRules');
        $method->setAccessible(true);
        
        $dateResult = $method->invoke($this->analyzer, $dateRules);
        $dateTimeResult = $method->invoke($this->analyzer, $dateTimeRules);
        
        expect($dateResult)
            ->toBeArray()
            ->toHaveKeys(['type', 'format'])
            ->and($dateResult['type'])->toBe('string')
            ->and($dateResult['format'])->toBe('date');
            
        expect($dateTimeResult)
            ->toBeArray()
            ->toHaveKeys(['type', 'format', 'example'])
            ->and($dateTimeResult['type'])->toBe('string')
            ->and($dateTimeResult['format'])->toBe('date-time')
            ->and($dateTimeResult['description'])->toContain('Must match the format');
    }
}
