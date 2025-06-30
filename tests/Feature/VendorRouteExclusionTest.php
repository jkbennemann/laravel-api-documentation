<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class VendorRouteExclusionTest extends TestCase
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

    public function test_vendor_class_detection()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => true,
            'api-documentation.excluded_routes' => [],
            'api-documentation.excluded_methods' => [],
        ]);

        // Test vendor class detection with improved logic
        $vendorClasses = [
            'Laravel\\Telescope\\Http\\Controllers\\TelescopeController',
            'Laravel\\Horizon\\Http\\Controllers\\HomeController',
            'Spatie\\LaravelIgnition\\Http\\Controllers\\ExecuteSolutionController',
            'Facade\\Ignition\\Http\\Controllers\\ShareReportController',
            'Laravel\\Sanctum\\Http\\Controllers\\CsrfCookieController',
        ];

        foreach ($vendorClasses as $class) {
            $isVendor = $this->invokePrivateMethod(
                $routeComposition,
                'isVendorClass',
                [$class]
            );

            // With improved logic, all these should be detected as vendor classes
            $this->assertTrue($isVendor, "Class {$class} should be detected as vendor with improved logic");
        }

        // Test non-vendor classes
        $nonVendorClasses = [
            'App\\Http\\Controllers\\UserController',
            'Domain\\Auth\\Http\\Controllers\\LoginController',
            'JkBennemann\\LaravelApiDocumentation\\Tests\\Stubs\\Controllers\\SimpleController',
        ];

        foreach ($nonVendorClasses as $class) {
            $isVendor = $this->invokePrivateMethod(
                $routeComposition,
                'isVendorClass',
                [$class]
            );

            $this->assertFalse($isVendor, "Class {$class} should NOT be detected as vendor");
        }
    }

    public function test_vendor_exclusion_with_improved_detection()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => false,
            'api-documentation.excluded_routes' => [],
            'api-documentation.excluded_methods' => [],
        ]);

        // Test routes that should be excluded with improved vendor detection
        $testCases = [
            // All vendor routes should now be properly detected and excluded
            ['uri' => 'telescope/requests', 'method' => 'GET', 'isVendor' => true, 'shouldBeSkipped' => true],
            ['uri' => '_ignition/health-check', 'method' => 'GET', 'isVendor' => true, 'shouldBeSkipped' => true],
            ['uri' => 'horizon/api/stats', 'method' => 'GET', 'isVendor' => true, 'shouldBeSkipped' => true],
            ['uri' => 'sanctum/csrf-cookie', 'method' => 'GET', 'isVendor' => true, 'shouldBeSkipped' => true],

            // Non-vendor routes should not be skipped
            ['uri' => 'api/users', 'method' => 'GET', 'isVendor' => false, 'shouldBeSkipped' => false],
            ['uri' => 'dashboard', 'method' => 'GET', 'isVendor' => false, 'shouldBeSkipped' => false],
        ];

        foreach ($testCases as $case) {
            $result = $this->invokePrivateMethod(
                $routeComposition,
                'shouldBeSkipped',
                [$case['uri'], $case['method'], $case['isVendor']]
            );

            $this->assertEquals(
                $case['shouldBeSkipped'],
                $result,
                "Route {$case['uri']} with isVendor={$case['isVendor']} should ".
                ($case['shouldBeSkipped'] ? 'be' : 'not be').' skipped'
            );
        }
    }

    public function test_specific_ignition_controller_detection()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => false,
            'api-documentation.excluded_routes' => [],
            'api-documentation.excluded_methods' => [],
        ]);

        // Test the actual ignition controllers found in the API Gateway application
        $ignitionControllers = [
            'Spatie\\LaravelIgnition\\Http\\Controllers\\ExecuteSolutionController',
            'Spatie\\LaravelIgnition\\Http\\Controllers\\HealthCheckController',
            'Spatie\\LaravelIgnition\\Http\\Controllers\\UpdateConfigController',
        ];

        foreach ($ignitionControllers as $controller) {
            $isVendor = $this->invokePrivateMethod(
                $routeComposition,
                'isVendorClass',
                [$controller]
            );

            $this->assertTrue(
                $isVendor,
                "Ignition controller {$controller} should be detected as vendor"
            );
        }
    }

    public function test_improved_vendor_detection_patterns()
    {
        $routeComposition = $this->createRouteCompositionWithConfig([
            'api-documentation.include_vendor_routes' => false,
            'api-documentation.excluded_routes' => [],
            'api-documentation.excluded_methods' => [],
        ]);

        $testClasses = [
            'Laravel\\Telescope\\Http\\Controllers\\TelescopeController' => true,
            'Laravel\\Horizon\\Http\\Controllers\\HomeController' => true,
            'Spatie\\LaravelIgnition\\Http\\Controllers\\ExecuteSolutionController' => true,
            'Facade\\Ignition\\Http\\Controllers\\ShareReportController' => true,
            'Laravel\\Sanctum\\Http\\Controllers\\CsrfCookieController' => true,
            'Filament\\Http\\Controllers\\AssetController' => true,
            'Barryvdh\\Debugbar\\Controllers\\BaseController' => true,
            'Intervention\\Image\\ImageManager' => true,
            'League\\Flysystem\\Filesystem' => true,
            'App\\Http\\Controllers\\UserController' => false,
            'Domain\\Auth\\Http\\Controllers\\LoginController' => false,
            'JkBennemann\\LaravelApiDocumentation\\Tests\\Stubs\\Controllers\\SimpleController' => false,
        ];

        foreach ($testClasses as $className => $expectedIsVendor) {
            $isVendor = $this->invokePrivateMethod(
                $routeComposition,
                'isVendorClass',
                [$className]
            );

            $this->assertEquals(
                $expectedIsVendor,
                $isVendor,
                "Class {$className} should ".($expectedIsVendor ? 'be' : 'not be').' detected as vendor'
            );
        }
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
