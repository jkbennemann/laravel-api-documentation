<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class RouteExclusionSimpleTest extends TestCase
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

    public function test_vendor_routes_exclusion_works()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => false,
            'api-documentation.excluded_routes' => [],
            'api-documentation.excluded_methods' => [],
        ]);

        $result = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['/api/users', 'GET', true] // isVendorClass = true
        );

        $this->assertTrue($result, 'Vendor routes should be skipped when include_vendor_routes is false');
    }

    public function test_vendor_routes_inclusion_works()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => [],
            'api-documentation.excluded_methods' => [],
        ]);

        $result = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['/api/users', 'GET', true] // isVendorClass = true
        );

        $this->assertFalse($result, 'Vendor routes should not be skipped when include_vendor_routes is true');
    }

    public function test_excluded_routes_patterns_work()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => ['api/admin/*', 'api/internal/*'],
            'api-documentation.excluded_methods' => [],
        ]);

        // Test excluded patterns
        $result1 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/admin/users', 'GET', false]
        );
        $this->assertTrue($result1, 'Routes matching excluded patterns should be skipped');

        $result2 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/internal/config', 'POST', false]
        );
        $this->assertTrue($result2, 'Routes matching excluded patterns should be skipped');

        // Test non-excluded pattern
        $result3 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/public/users', 'GET', false]
        );
        $this->assertFalse($result3, 'Routes not matching excluded patterns should not be skipped');
    }

    public function test_negation_patterns_work()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => ['!api/*'], // Exclude all except api/*
            'api-documentation.excluded_methods' => [],
        ]);

        // Routes matching the negation pattern should NOT be skipped
        $result1 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/users', 'GET', false]
        );
        $this->assertFalse($result1, 'Routes matching negation pattern should not be skipped');

        // Routes NOT matching the negation pattern should be skipped
        $result2 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['admin/users', 'GET', false]
        );
        $this->assertTrue($result2, 'Routes not matching negation pattern should be skipped');
    }

    public function test_excluded_methods_work()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => [],
            'api-documentation.excluded_methods' => ['HEAD', 'OPTIONS'],
        ]);

        // Test excluded methods
        $result1 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/users', 'HEAD', false]
        );
        $this->assertTrue($result1, 'Excluded HTTP methods should be skipped');

        $result2 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/users', 'OPTIONS', false]
        );
        $this->assertTrue($result2, 'Excluded HTTP methods should be skipped');

        // Test non-excluded method
        $result3 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/users', 'GET', false]
        );
        $this->assertFalse($result3, 'Non-excluded HTTP methods should not be skipped');
    }

    public function test_wildcard_patterns_work()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => ['*/admin', 'api/v1/*', 'vendor/*'],
            'api-documentation.excluded_methods' => [],
        ]);

        // Test various wildcard patterns
        $result1 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['app/admin', 'GET', false]
        );
        $this->assertTrue($result1, 'Route matching */admin pattern should be skipped');

        $result2 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/v1/users', 'GET', false]
        );
        $this->assertTrue($result2, 'Route matching api/v1/* pattern should be skipped');

        $result3 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['vendor/package/route', 'GET', false]
        );
        $this->assertTrue($result3, 'Route matching vendor/* pattern should be skipped');

        // Test non-matching routes
        $result4 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/v2/users', 'GET', false]
        );
        $this->assertFalse($result4, 'Route not matching patterns should not be skipped');
    }

    public function test_combined_filtering_logic()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => false, // Exclude vendor routes
            'api-documentation.excluded_routes' => ['api/admin/*'], // Also exclude admin routes
            'api-documentation.excluded_methods' => ['HEAD', 'OPTIONS'], // Also exclude HEAD/OPTIONS
        ]);

        // Test vendor route (should be excluded)
        $result1 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/users', 'GET', true] // isVendorClass = true
        );
        $this->assertTrue($result1, 'Vendor routes should be excluded');

        // Test admin route (should be excluded)
        $result2 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/admin/users', 'GET', false] // non-vendor but admin
        );
        $this->assertTrue($result2, 'Admin routes should be excluded');

        // Test excluded method (should be excluded)
        $result3 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/users', 'HEAD', false] // non-vendor, non-admin but HEAD method
        );
        $this->assertTrue($result3, 'HEAD method should be excluded');

        // Test valid route (should not be excluded)
        $result4 = $this->invokePrivateMethod(
            $routeComposition,
            'shouldBeSkipped',
            ['api/users', 'GET', false] // non-vendor, non-admin, GET method
        );
        $this->assertFalse($result4, 'Valid routes should not be excluded');
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
