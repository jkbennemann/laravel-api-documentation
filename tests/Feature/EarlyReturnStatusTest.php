<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\EarlyReturnStatusController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * A status returned from a guard clause belongs in the document.
 *
 * `abort(404)` was already picked up. `return response()->json($body, 404)` was not, even though the
 * two are interchangeable in ordinary controller code — so a method written the second way
 * documented only its happy path. Found by driving a real API against its own published
 * specification: the endpoint's NORMAL answer was the undocumented one.
 */
class EarlyReturnStatusTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        return app(OpenApiEmitter::class)->emit(
            app(RouteDiscovery::class)->discover(),
            config('api-documentation'),
        );
    }

    public function test_a_guard_clause_returning_json_documents_its_status(): void
    {
        Route::get('api/previews/me', [EarlyReturnStatusController::class, 'guardClause']);

        $responses = $this->generateSpec()['paths']['/api/previews/me']['get']['responses'] ?? [];

        $this->assertArrayHasKey('200', $responses, 'The happy path must still be documented.');
        $this->assertArrayHasKey('404', $responses, 'A guard clause returning 404 is a documented outcome.');
    }

    public function test_every_guard_in_a_method_is_documented(): void
    {
        Route::post('api/guards', [EarlyReturnStatusController::class, 'severalGuards']);

        $responses = $this->generateSpec()['paths']['/api/guards']['post']['responses'] ?? [];

        foreach (['200', '403', '422'] as $status) {
            $this->assertArrayHasKey($status, $responses, "Status {$status} is returned and must be documented.");
        }
    }

    public function test_a_declared_success_does_not_hide_an_undeclared_guard(): void
    {
        Route::get('api/declared/me', [EarlyReturnStatusController::class, 'declaredSuccessWithUndeclaredGuard']);

        $operation = $this->generateSpec()['paths']['/api/declared/me']['get'];

        $this->assertArrayHasKey('200', $operation['responses']);
        $this->assertSame('The current preview', $operation['responses']['200']['description'] ?? null,
            'The declared status keeps the description the attribute gave it.');
        $this->assertArrayHasKey('404', $operation['responses'],
            'An attribute owns the statuses it names, not the ones it says nothing about.');
    }

    public function test_an_attribute_still_owns_the_status_it_declares(): void
    {
        Route::get('api/declared/only', [EarlyReturnStatusController::class, 'declaredStatusIsNotRestated']);

        $responses = $this->generateSpec()['paths']['/api/declared/only']['get']['responses'];

        $this->assertSame('Declared success', $responses['200']['description'] ?? null);
    }

    public function test_a_status_raised_by_a_guard_helper_is_documented(): void
    {
        Route::get('api/smtp-config', [EarlyReturnStatusController::class, 'guardedByHelper']);

        $responses = $this->generateSpec()['paths']['/api/smtp-config']['get']['responses'];

        $this->assertArrayHasKey('403', $responses, 'abort_if in a guard helper is a documented outcome.');
        $this->assertArrayHasKey('401', $responses, 'abort_unless in a guard helper is too.');
        $this->assertSame(
            'Your plan does not include this feature.',
            $responses['403']['description'] ?? null,
            "The helper's own abort message describes the status.",
        );
    }

    public function test_a_guard_before_a_resource_response_is_documented(): void
    {
        Route::get('api/guarded-resource', [EarlyReturnStatusController::class, 'guardBeforeResource']);

        $responses = $this->generateSpec()['paths']['/api/guarded-resource']['get']['responses'] ?? [];

        $this->assertArrayHasKey('410', $responses);
        $this->assertArrayHasKey('200', $responses);
    }
}
