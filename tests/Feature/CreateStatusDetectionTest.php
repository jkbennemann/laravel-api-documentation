<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\CreateStatusController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * A JsonResource returned from a create documents 201, because that is what Laravel sends.
 *
 * `ResourceResponse::calculateStatus()` answers 201 whenever the wrapped model reports
 * `wasRecentlyCreated`. Nothing in the signature says so, so reading the return type alone
 * documented 200 for every create in a consuming application — measured at 26 of 35 collection
 * POSTs in one real deployment, which breaks any generated client that branches on `status === 200`.
 */
class CreateStatusDetectionTest extends TestCase
{
    private function statuses(string $path, string $method = 'post'): array
    {
        app(SchemaRegistry::class)->reset();

        $spec = app(OpenApiEmitter::class)->emit(
            app(RouteDiscovery::class)->discover(),
            config('api-documentation'),
        );

        // Keys arrive as ints here and as strings once serialised to JSON; normalise so the
        // assertions read the same either way.
        return array_map('strval', array_keys($spec['paths'][$path][$method]['responses'] ?? []));
    }

    public function test_a_post_that_creates_a_model_documents_201(): void
    {
        Route::post('api/widgets', [CreateStatusController::class, 'store']);

        expect($this->statuses('/api/widgets'))->toContain('201');
    }

    public function test_a_post_that_persists_with_save_documents_201(): void
    {
        Route::post('api/widgets', [CreateStatusController::class, 'storeViaSave']);

        expect($this->statuses('/api/widgets'))->toContain('201');
    }

    /** updateOrCreate genuinely answers either, so documenting one of them would be a guess. */
    public function test_a_conditional_create_documents_both_statuses(): void
    {
        Route::post('api/widgets', [CreateStatusController::class, 'upsert']);

        $statuses = $this->statuses('/api/widgets');

        expect($statuses)->toContain('201');
        expect($statuses)->toContain('200');
    }

    public function test_a_post_that_persists_nothing_stays_200(): void
    {
        Route::post('api/widgets/recalculate', [CreateStatusController::class, 'recalculate']);

        $statuses = $this->statuses('/api/widgets/recalculate');

        expect($statuses)->toContain('200');
        expect($statuses)->not()->toContain('201');
    }

    /** The rule is scoped to POST. The same resource on a read is never a create. */
    public function test_a_get_is_never_201(): void
    {
        Route::get('api/widgets/first', [CreateStatusController::class, 'show']);

        expect($this->statuses('/api/widgets/first', 'get'))->not()->toContain('201');
    }

    public function test_creating_a_child_and_returning_the_parent_stays_200(): void
    {
        Route::post('api/policies/{parent}/steps', [CreateStatusController::class, 'addChildReturningParent']);

        $statuses = $this->statuses('/api/policies/{parent}/steps');

        expect($statuses)->toContain('200');
        expect($statuses)->not()->toContain('201');
    }

    public function test_saving_a_model_the_action_did_not_create_stays_200(): void
    {
        Route::post('api/incidents/{user}/acknowledge', [CreateStatusController::class, 'acknowledge']);

        $statuses = $this->statuses('/api/incidents/{user}/acknowledge');

        expect($statuses)->toContain('200');
        expect($statuses)->not()->toContain('201');
    }

    public function test_a_create_inside_a_transaction_is_still_a_create(): void
    {
        Route::post('api/tx-users', [CreateStatusController::class, 'storeInsideTransaction']);

        expect($this->statuses('/api/tx-users'))->toContain('201');
    }
}
