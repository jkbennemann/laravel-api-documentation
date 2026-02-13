<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\ConditionalController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ConditionalValidationTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_required_if_adds_description(): void
    {
        $mapper = new ValidationRuleMapper;

        $schema = $mapper->mapRules(['required_if:type,company', 'string']);
        expect($schema->description)->toContain('Required if');
        expect($schema->description)->toContain('type');
        expect($schema->description)->toContain('company');
    }

    public function test_required_with_adds_description(): void
    {
        $mapper = new ValidationRuleMapper;

        $schema = $mapper->mapRules(['required_with:company_name', 'string']);
        expect($schema->description)->toContain('Required with');
        expect($schema->description)->toContain('company_name');
    }

    public function test_required_without_adds_description(): void
    {
        $mapper = new ValidationRuleMapper;

        $schema = $mapper->mapRules(['required_without:company_name', 'string']);
        expect($schema->description)->toContain('Required without');
        expect($schema->description)->toContain('company_name');
    }

    public function test_conditional_fields_not_marked_required(): void
    {
        $mapper = new ValidationRuleMapper;

        // required_if is NOT the same as required
        expect($mapper->isRequired(['required_if:type,company', 'string']))->toBeFalse();
        expect($mapper->isRequired(['required_with:other', 'string']))->toBeFalse();
        expect($mapper->isRequired(['required_without:other', 'string']))->toBeFalse();

        // plain required IS required
        expect($mapper->isRequired(['required', 'string']))->toBeTrue();
    }

    public function test_conditional_rules_in_full_spec(): void
    {
        Route::post('api/conditional', [ConditionalController::class, 'store']);

        $spec = $this->generateSpec();

        $schema = $spec['paths']['/api/conditional']['post']['requestBody']['content']['application/json']['schema'] ?? null;
        expect($schema)->not()->toBeNull();

        // 'type' should be required (plain required)
        expect($schema['required'])->toContain('type');

        // company_name should NOT be in required (it's required_if)
        expect($schema['required'])->not()->toContain('company_name');

        // company_name should have a description about the condition
        $companyName = $schema['properties']['company_name'] ?? null;
        expect($companyName)->not()->toBeNull();
        expect($companyName['description'])->toContain('Required if');
    }

    public function test_type_field_has_enum(): void
    {
        Route::post('api/conditional', [ConditionalController::class, 'store']);

        $spec = $this->generateSpec();

        $schema = $spec['paths']['/api/conditional']['post']['requestBody']['content']['application/json']['schema'];
        $typeProp = $schema['properties']['type'] ?? null;
        expect($typeProp)->not()->toBeNull();
        expect($typeProp['enum'])->toBe(['individual', 'company']);
    }
}
