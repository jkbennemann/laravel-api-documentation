<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\SampleResource;

/**
 * Controllers that return an error status from an early `return`, rather than aborting.
 *
 * `abort(404)` was already detected; `return response()->json($body, 404)` was not, and the two are
 * interchangeable in ordinary Laravel code. A method built this way documented only its happy path,
 * so the status a caller sees most often was the one missing from the reference.
 */
class EarlyReturnStatusController extends Controller
{
    /** The shape that started this: a guard clause returning JSON with an explicit status. */
    public function guardClause(): JsonResponse
    {
        $found = false;

        if (! $found) {
            return response()->json([
                'message' => 'No active preview found.',
                'code' => 'preview_not_found',
            ], 404);
        }

        return response()->json(['data' => []]);
    }

    /** Several guards, each with its own status. */
    public function severalGuards(): JsonResponse
    {
        if (request()->missing('token')) {
            return response()->json(['message' => 'Token required.'], 422);
        }

        if (! request()->boolean('allowed')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => []]);
    }

    /**
     * A declared success shape AND an undeclared guard clause — the shape that hid the defect.
     *
     * The attribute names 200. The 404 one line above it was declared by nobody and returned by the
     * code on every call without an active preview, which is most of them.
     */
    #[DataResponse(status: 200, description: 'The current preview')]
    public function declaredSuccessWithUndeclaredGuard(): JsonResponse
    {
        $found = false;

        if (! $found) {
            return response()->json(['message' => 'No active preview found.'], 404);
        }

        return response()->json(['data' => []]);
    }

    /** An attribute still wins for the status it names: no auto-detected 200 may displace it. */
    #[DataResponse(status: 200, description: 'Declared success')]
    public function declaredStatusIsNotRestated(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    /**
     * The guard-helper idiom: the status lives one call away from the action.
     *
     * This is how most of a real controller is written, and reading only the action's own body
     * meant an endpoint that refuses most callers with a 403 documented no 403 at all.
     */
    #[DataResponse(status: 200, description: 'The configuration')]
    public function guardedByHelper(): JsonResponse
    {
        $this->enforcePlan();
        $this->enforceAdmin();

        return response()->json(['data' => []]);
    }

    private function enforcePlan(): void
    {
        abort_if(request()->boolean('free'), 403, 'Your plan does not include this feature.');
    }

    private function enforceAdmin(): void
    {
        abort_unless(request()->boolean('admin'), 401);
    }

    /** A guard clause in a method whose happy path is a JsonResource, not a JsonResponse. */
    public function guardBeforeResource(): JsonResponse
    {
        if (request()->missing('id')) {
            return response()->json(['message' => 'Gone.'], 410);
        }

        return (new SampleResource([]))->response();
    }
}
