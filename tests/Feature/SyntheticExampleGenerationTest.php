<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\ExampleGenerator;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\RegisterController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class SyntheticExampleGenerationTest extends TestCase
{
    private ExampleGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ExampleGenerator;
    }

    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    // --- Unit-level tests ---

    public function test_string_field_gets_example(): void
    {
        $schema = SchemaObject::string();
        $result = $this->generator->generate($schema);

        expect($result->example)->not()->toBeNull();
        expect($result->example)->toBeString();
    }

    public function test_integer_field_gets_example(): void
    {
        $schema = SchemaObject::integer();
        $result = $this->generator->generate($schema);

        expect($result->example)->not()->toBeNull();
        expect($result->example)->toBeInt();
    }

    public function test_number_field_gets_example(): void
    {
        $schema = SchemaObject::number();
        $result = $this->generator->generate($schema);

        expect($result->example)->not()->toBeNull();
        expect($result->example)->toBeFloat();
    }

    public function test_boolean_field_gets_example(): void
    {
        $schema = SchemaObject::boolean();
        $result = $this->generator->generate($schema);

        expect($result->example)->not()->toBeNull();
        expect($result->example)->toBeBool();
    }

    public function test_email_format_gets_email_example(): void
    {
        $schema = new SchemaObject(type: 'string', format: 'email');
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe('user@example.com');
    }

    public function test_uuid_format_gets_uuid_example(): void
    {
        $schema = new SchemaObject(type: 'string', format: 'uuid');
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe('550e8400-e29b-41d4-a716-446655440000');
    }

    public function test_date_format_gets_date_example(): void
    {
        $schema = new SchemaObject(type: 'string', format: 'date');
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe('2025-01-15');
    }

    public function test_date_time_format_gets_datetime_example(): void
    {
        $schema = new SchemaObject(type: 'string', format: 'date-time');
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe('2025-01-15T10:30:00Z');
    }

    public function test_enum_field_picks_first_value(): void
    {
        $schema = new SchemaObject(type: 'string', enum: ['active', 'inactive', 'pending']);
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe('active');
    }

    public function test_existing_example_not_overwritten(): void
    {
        $schema = new SchemaObject(type: 'string', example: 'keep me');
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe('keep me');
    }

    public function test_default_value_used_as_example(): void
    {
        $schema = new SchemaObject(type: 'string', default: 'my-default');
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe('my-default');
    }

    public function test_object_properties_get_examples(): void
    {
        $schema = SchemaObject::object([
            'name' => SchemaObject::string(),
            'age' => SchemaObject::integer(),
            'active' => SchemaObject::boolean(),
        ]);

        $result = $this->generator->generate($schema);

        // Object itself should NOT get an example
        expect($result->example)->toBeNull();

        // Each property should get an example
        expect($result->properties['name']->example)->toBe('Example name');
        expect($result->properties['age']->example)->toBe(25);
        expect($result->properties['active']->example)->toBe(true);
    }

    public function test_field_name_email_gets_email_example(): void
    {
        $schema = SchemaObject::object([
            'email' => SchemaObject::string(),
        ]);

        $result = $this->generator->generate($schema);

        expect($result->properties['email']->example)->toBe('user@example.com');
    }

    public function test_field_name_id_gets_integer_example(): void
    {
        $schema = SchemaObject::object([
            'user_id' => SchemaObject::integer(),
        ]);

        $result = $this->generator->generate($schema);

        expect($result->properties['user_id']->example)->toBe(1);
    }

    public function test_field_name_id_string_type_gets_string_example(): void
    {
        $schema = SchemaObject::object([
            'user_id' => SchemaObject::string(),
        ]);

        $result = $this->generator->generate($schema);

        expect($result->properties['user_id']->example)->toBe('1');
    }

    public function test_integer_with_min_max_respects_range(): void
    {
        $schema = new SchemaObject(type: 'integer', minimum: 10, maximum: 100);
        $result = $this->generator->generate($schema);

        expect($result->example)->toBeInt();
        expect($result->example)->toBeGreaterThanOrEqual(10);
        expect($result->example)->toBeLessThanOrEqual(100);
    }

    public function test_integer_with_only_minimum_uses_minimum(): void
    {
        $schema = new SchemaObject(type: 'integer', minimum: 5);
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe(5);
    }

    public function test_array_gets_single_item_example(): void
    {
        $schema = SchemaObject::array(SchemaObject::string());
        $result = $this->generator->generate($schema);

        expect($result->example)->toBe(['string']);
    }

    public function test_ref_schema_is_skipped(): void
    {
        $schema = SchemaObject::ref(new \JkBennemann\LaravelApiDocumentation\Data\Reference('#/components/schemas/User'));
        $result = $this->generator->generate($schema);

        expect($result->example)->toBeNull();
    }

    public function test_nested_object_gets_recursive_examples(): void
    {
        $schema = SchemaObject::object([
            'user' => SchemaObject::object([
                'email' => SchemaObject::string(),
                'name' => SchemaObject::string(),
            ]),
        ]);

        $result = $this->generator->generate($schema);

        expect($result->properties['user']->properties['email']->example)->toBe('user@example.com');
        expect($result->properties['user']->properties['name']->example)->toBe('Example name');
    }

    public function test_field_name_heuristics(): void
    {
        $schema = SchemaObject::object([
            'phone' => SchemaObject::string(),
            'url' => SchemaObject::string(),
            'password' => SchemaObject::string(),
            'token' => SchemaObject::string(),
            'slug' => SchemaObject::string(),
            'status' => SchemaObject::string(),
            'title' => SchemaObject::string(),
            'city' => SchemaObject::string(),
            'country' => SchemaObject::string(),
            'zip_code' => SchemaObject::string(),
            'page' => SchemaObject::integer(),
            'per_page' => SchemaObject::integer(),
            'price' => SchemaObject::number(),
        ]);

        $result = $this->generator->generate($schema);

        expect($result->properties['phone']->example)->toBe('+1-555-555-5555');
        expect($result->properties['url']->example)->toBe('https://example.com');
        expect($result->properties['password']->example)->toBe('password123');
        expect($result->properties['token']->example)->toBe('abc123def456');
        expect($result->properties['slug']->example)->toBe('example-slug');
        expect($result->properties['status']->example)->toBe('active');
        expect($result->properties['title']->example)->toBe('Example title');
        expect($result->properties['city']->example)->toBe('New York');
        expect($result->properties['country']->example)->toBe('US');
        expect($result->properties['zip_code']->example)->toBe('10001');
        expect($result->properties['page']->example)->toBe(1);
        expect($result->properties['per_page']->example)->toBe(15);
        expect($result->properties['price']->example)->toBe(99.99);
    }

    public function test_existing_property_examples_preserved_in_object(): void
    {
        $schema = SchemaObject::object([
            'name' => new SchemaObject(type: 'string', example: 'Custom Name'),
            'email' => SchemaObject::string(),
        ]);

        $result = $this->generator->generate($schema);

        expect($result->properties['name']->example)->toBe('Custom Name');
        expect($result->properties['email']->example)->toBe('user@example.com');
    }

    // --- Integration tests ---

    public function test_full_pipeline_request_body_has_examples(): void
    {
        Route::post('api/register', [RegisterController::class, 'store']);

        $spec = $this->generateSpec();

        $requestBody = $spec['paths']['/api/register']['post']['requestBody'] ?? null;
        expect($requestBody)->not()->toBeNull();

        $properties = $requestBody['content']['application/json']['schema']['properties'] ?? [];
        expect($properties)->not()->toBeEmpty();

        // RegisterRequest has: name, email, password — each should have an example
        foreach ($properties as $name => $prop) {
            expect($prop)->toHaveKey('example');
        }
    }

    public function test_full_pipeline_response_has_examples(): void
    {
        Route::apiResource('api/posts', PostController::class);

        $spec = $this->generateSpec();

        // Check the show endpoint response
        $showOp = $spec['paths']['/api/posts/{post}']['get'] ?? null;
        expect($showOp)->not()->toBeNull();

        $responseContent = $showOp['responses']['200']['content']['application/json'] ?? null;
        expect($responseContent)->not()->toBeNull();

        // The response schema should have properties with examples
        // (either inline or in component schemas)
        $schema = $responseContent['schema'] ?? [];

        // Collect all leaf properties from the response schema tree
        $properties = $schema['properties'] ?? [];

        // If it's a $ref, check the component schema instead
        if (isset($schema['$ref'])) {
            $refName = str_replace('#/components/schemas/', '', $schema['$ref']);
            $properties = $spec['components']['schemas'][$refName]['properties'] ?? [];
        }

        // For a wrapped data response, check inside data.properties
        if (isset($properties['data']['properties'])) {
            $properties = $properties['data']['properties'];
        } elseif (isset($properties['data']['$ref'])) {
            $refName = str_replace('#/components/schemas/', '', $properties['data']['$ref']);
            $properties = $spec['components']['schemas'][$refName]['properties'] ?? [];
        }

        expect($properties)->not()->toBeEmpty();

        $missingExamples = [];
        foreach ($properties as $name => $prop) {
            $type = $prop['type'] ?? null;

            // Skip objects (including nullable objects) and $ref — their sub-properties have examples
            if ($type === 'object' || (is_array($type) && in_array('object', $type, true))) {
                continue;
            }
            if (isset($prop['$ref'])) {
                continue;
            }
            if (! array_key_exists('example', $prop)) {
                $missingExamples[$name] = $prop;
            }
        }

        expect($missingExamples)->toBeEmpty();
    }
}
