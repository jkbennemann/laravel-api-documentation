<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class RouteFilteringTest extends TestCase
{
    private function discoverUris(array $configOverrides = []): array
    {
        app(SchemaRegistry::class)->reset();

        $config = array_merge([
            'excluded_routes' => [],
            'excluded_methods' => ['HEAD', 'OPTIONS'],
            'include_vendor_routes' => false,
        ], $configOverrides);

        $discovery = new RouteDiscovery(app('router'), $config);
        $contexts = $discovery->discover();

        return array_map(fn ($ctx) => $ctx->route->uri, $contexts);
    }

    // -----------------------------------------------------------------
    // 1. Exclusion pattern filters matching URIs
    // -----------------------------------------------------------------

    public function test_excluded_routes_pattern_filters_matching_uris(): void
    {
        Route::get('api/users', [SimpleController::class, 'simple']);
        Route::get('admin/dashboard', [SimpleController::class, 'simple']);
        Route::get('admin/settings', [SimpleController::class, 'simple']);

        $uris = $this->discoverUris(['excluded_routes' => ['admin/*']]);

        expect($uris)->toContain('api/users');
        expect($uris)->not()->toContain('admin/dashboard');
        expect($uris)->not()->toContain('admin/settings');
    }

    // -----------------------------------------------------------------
    // 2. Inclusion pattern includes only matching routes
    // -----------------------------------------------------------------

    public function test_inclusion_pattern_includes_only_matching_routes(): void
    {
        Route::get('api/users', [SimpleController::class, 'simple']);
        Route::get('api/posts', [SimpleController::class, 'simple']);
        Route::get('web/dashboard', [SimpleController::class, 'simple']);

        $uris = $this->discoverUris(['excluded_routes' => ['!api/*']]);

        expect($uris)->toContain('api/users');
        expect($uris)->toContain('api/posts');
        expect($uris)->not()->toContain('web/dashboard');
    }

    // -----------------------------------------------------------------
    // 3. HEAD and OPTIONS excluded by default
    // -----------------------------------------------------------------

    public function test_excluded_methods_filters_head_and_options(): void
    {
        Route::get('api/users', [SimpleController::class, 'simple']);

        $contexts = (new RouteDiscovery(app('router'), [
            'excluded_routes' => [],
            'excluded_methods' => ['HEAD', 'OPTIONS'],
            'include_vendor_routes' => false,
        ]))->discover();

        $methods = [];
        foreach ($contexts as $ctx) {
            $methods[] = $ctx->route->httpMethod();
        }

        expect($methods)->toContain('GET');
        expect($methods)->not()->toContain('HEAD');
        expect($methods)->not()->toContain('OPTIONS');
    }

    // -----------------------------------------------------------------
    // 4. Closure routes are excluded
    // -----------------------------------------------------------------

    public function test_closure_routes_are_excluded(): void
    {
        Route::get('api/closure', fn () => 'hello');
        Route::get('api/controller', [SimpleController::class, 'simple']);

        $uris = $this->discoverUris();

        expect($uris)->toContain('api/controller');
        expect($uris)->not()->toContain('api/closure');
    }

    // -----------------------------------------------------------------
    // 5. Wildcard exclusion patterns work
    // -----------------------------------------------------------------

    public function test_wildcard_exclusion_patterns_work(): void
    {
        Route::get('api/users', [SimpleController::class, 'simple']);
        Route::get('debug/routes', [SimpleController::class, 'simple']);
        Route::get('_ignition/health', [SimpleController::class, 'simple']);

        $uris = $this->discoverUris([
            'excluded_routes' => ['debug/*', '_ignition/*'],
        ]);

        expect($uris)->toContain('api/users');
        expect($uris)->not()->toContain('debug/routes');
        expect($uris)->not()->toContain('_ignition/health');
    }

    // -----------------------------------------------------------------
    // 6. Combined filtering works
    // -----------------------------------------------------------------

    public function test_combined_filtering_works(): void
    {
        Route::get('api/users', [SimpleController::class, 'simple']);
        Route::get('admin/panel', [SimpleController::class, 'simple']);
        Route::get('telescope/requests', [SimpleController::class, 'simple']);
        Route::get('api/closure', fn () => 'closure');

        $uris = $this->discoverUris([
            'excluded_routes' => ['admin/*', 'telescope/*'],
        ]);

        expect($uris)->toContain('api/users');
        expect($uris)->not()->toContain('admin/panel');
        expect($uris)->not()->toContain('telescope/requests');
        expect($uris)->not()->toContain('api/closure'); // closure excluded too
    }

    // -----------------------------------------------------------------
    // 7. All routes included when no exclusions
    // -----------------------------------------------------------------

    public function test_all_routes_included_when_no_exclusions(): void
    {
        Route::get('api/users', [SimpleController::class, 'simple']);
        Route::post('api/users', [SimpleController::class, 'simple']);
        Route::get('web/home', [SimpleController::class, 'simple']);

        $uris = $this->discoverUris(['excluded_routes' => []]);

        expect($uris)->toContain('api/users');
        expect($uris)->toContain('web/home');
        expect(count($uris))->toBeGreaterThanOrEqual(3);
    }
}
