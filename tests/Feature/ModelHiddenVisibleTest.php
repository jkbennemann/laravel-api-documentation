<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\EloquentModelAnalyzer;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\UserController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ModelHiddenVisibleTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_hidden_fields_detected(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        $hidden = $analyzer->getHiddenFields(User::class);
        expect($hidden)->toContain('password');
        expect($hidden)->toContain('remember_token');
    }

    public function test_is_hidden_returns_true_for_hidden_field(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        expect($analyzer->isHidden(User::class, 'password'))->toBeTrue();
        expect($analyzer->isHidden(User::class, 'name'))->toBeFalse();
    }

    public function test_should_expose_property_respects_hidden(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        expect($analyzer->shouldExposeProperty(User::class, 'name'))->toBeTrue();
        expect($analyzer->shouldExposeProperty(User::class, 'email'))->toBeTrue();
        expect($analyzer->shouldExposeProperty(User::class, 'password'))->toBeFalse();
        expect($analyzer->shouldExposeProperty(User::class, 'remember_token'))->toBeFalse();
    }

    public function test_hidden_field_in_resource_gets_warning_description(): void
    {
        Route::get('api/users/{user}', [UserController::class, 'show']);

        $spec = $this->generateSpec();

        // Find the UserResource schema in components
        $schemas = $spec['components']['schemas'] ?? [];
        $userSchema = null;
        foreach ($schemas as $name => $schema) {
            if (str_contains($name, 'UserResource') || str_contains($name, 'User')) {
                if (isset($schema['properties']['password'])) {
                    $userSchema = $schema;

                    break;
                }
            }
        }

        // If the schema is inlined (not in components), check the response
        if ($userSchema === null) {
            $responseSchema = $spec['paths']['/api/users/{user}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;
            expect($responseSchema)->not()->toBeNull();

            // Resolve through data wrapper if present
            if (isset($responseSchema['properties']['data'])) {
                $innerSchema = $responseSchema['properties']['data'];
                // May be a $ref
                if (isset($innerSchema['$ref'])) {
                    $refName = str_replace('#/components/schemas/', '', $innerSchema['$ref']);
                    $userSchema = $schemas[$refName] ?? null;
                } else {
                    $userSchema = $innerSchema;
                }
            }
        }

        expect($userSchema)->not()->toBeNull();
        expect($userSchema['properties'])->toHaveKey('password');

        $passwordProp = $userSchema['properties']['password'];
        expect($passwordProp)->toHaveKey('description');
        expect($passwordProp['description'])->toContain('$hidden');
    }

    public function test_non_hidden_field_has_no_warning(): void
    {
        Route::get('api/users/{user}', [UserController::class, 'show']);

        $spec = $this->generateSpec();

        $schemas = $spec['components']['schemas'] ?? [];
        $userSchema = null;
        foreach ($schemas as $name => $schema) {
            if (isset($schema['properties']['name']) && isset($schema['properties']['password'])) {
                $userSchema = $schema;

                break;
            }
        }

        if ($userSchema === null) {
            $responseSchema = $spec['paths']['/api/users/{user}']['get']['responses']['200']['content']['application/json']['schema'] ?? null;
            if (isset($responseSchema['properties']['data'])) {
                $innerSchema = $responseSchema['properties']['data'];
                if (isset($innerSchema['$ref'])) {
                    $refName = str_replace('#/components/schemas/', '', $innerSchema['$ref']);
                    $userSchema = $schemas[$refName] ?? null;
                } else {
                    $userSchema = $innerSchema;
                }
            }
        }

        expect($userSchema)->not()->toBeNull();

        $nameProp = $userSchema['properties']['name'];
        // Name field should NOT have the hidden warning
        if (isset($nameProp['description'])) {
            expect($nameProp['description'])->not()->toContain('$hidden');
        }
    }

    public function test_get_visible_fields_returns_empty_when_not_set(): void
    {
        $analyzer = new EloquentModelAnalyzer(new TypeMapper);

        // User model doesn't set $visible, should return empty
        $visible = $analyzer->getVisibleFields(User::class);
        expect($visible)->toBeEmpty();
    }
}
