<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class IgnitionRouteExclusionTest extends TestCase
{
    private function createRouteCompositionWithConfig(array $config): RouteComposition
    {
        // Set the configuration
        foreach ($config as $key => $value) {
            config([$key => $value]);
        }

        // Create a fresh instance with the new configuration
        $this->app->forgetInstance(RouteComposition::class);

        return $this->app->make(RouteComposition::class);
    }

    public function test_ignition_routes_exclusion_with_underscore_pattern()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => ['_*'],
            'api-documentation.excluded_methods' => [],
        ]);

        // Test _ignition routes (should be excluded)
        $result1 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['_ignition/health-check', 'GET', false]
        );
        $this->assertTrue($result1, 'Routes matching _* pattern should be skipped');

        $result2 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['_ignition/execute-solution', 'POST', false]
        );
        $this->assertTrue($result2, 'Routes matching _* pattern should be skipped');
    }

    public function test_ignition_routes_exclusion_with_slash_underscore_pattern()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => ['/_*'],
            'api-documentation.excluded_methods' => [],
        ]);

        // Test /_ignition routes (should be excluded)
        $result1 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['/_ignition/health-check', 'GET', false]
        );
        $this->assertTrue($result1, 'Routes matching /_* pattern should be skipped');

        $result2 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['/_ignition/execute-solution', 'POST', false]
        );
        $this->assertTrue($result2, 'Routes matching /_* pattern should be skipped');
    }

    public function test_multiple_ignition_exclusion_patterns()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => ['_*', '/_*', '_ignition/*', '/_ignition/*'],
            'api-documentation.excluded_methods' => [],
        ]);

        // Test various ignition route formats
        $testCases = [
            '_ignition/health-check',
            '/_ignition/health-check',
            '_ignition/execute-solution',
            '/_ignition/execute-solution',
            '_ignition/share-report',
            '/_ignition/share-report',
        ];

        foreach ($testCases as $uri) {
            $result = $this->invokePrivateMethod(
                $routeComposition,
                'shouldBeSkipped',
                [$uri, 'GET', false]
            );
            $this->assertTrue($result, "Route {$uri} should be excluded by ignition patterns");
        }
    }

    public function test_pattern_matching_debug()
    {
        // Test Laravel's Str::is method directly to debug pattern matching
        $this->assertTrue(\Illuminate\Support\Str::is('_*', '_ignition/health-check'), '_* should match _ignition/health-check');
        $this->assertTrue(\Illuminate\Support\Str::is('/_*', '/_ignition/health-check'), '/_* should match /_ignition/health-check');
        $this->assertTrue(\Illuminate\Support\Str::is('_ignition/*', '_ignition/health-check'), '_ignition/* should match _ignition/health-check');
        $this->assertTrue(\Illuminate\Support\Str::is('/_ignition/*', '/_ignition/health-check'), '/_ignition/* should match /_ignition/health-check');

        // Test non-ignition routes should not match
        $this->assertFalse(\Illuminate\Support\Str::is('_*', 'api/users'), '_* should not match api/users');
        $this->assertFalse(\Illuminate\Support\Str::is('/_*', '/api/users'), '/_* should not match /api/users');
    }

    /**
     * Helper method to invoke private methods for testing
     */
    private function invokePrivateMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
