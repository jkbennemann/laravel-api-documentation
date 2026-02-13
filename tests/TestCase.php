<?php

namespace JkBennemann\LaravelApiDocumentation\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use JkBennemann\LaravelApiDocumentation\LaravelApiDocumentationServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'JkBennemann\\LaravelApiDocumentation\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelApiDocumentationServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
        $migration = include __DIR__.'/../database/migrations/create_laravel-api-documentation_table.php.stub';
        $migration->up();
        */
    }

    /**
     * Resolve a $ref schema to its component definition within a spec.
     */
    protected function resolveSchemaRef(array $schema, array $spec): array
    {
        if (isset($schema['$ref']) && str_starts_with($schema['$ref'], '#/components/schemas/')) {
            $name = substr($schema['$ref'], strlen('#/components/schemas/'));

            return $spec['components']['schemas'][$name] ?? $schema;
        }

        return $schema;
    }
}
