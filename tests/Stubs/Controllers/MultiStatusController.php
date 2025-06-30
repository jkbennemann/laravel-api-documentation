<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class MultiStatusController extends Controller
{
    public function withMultipleResponses(): JsonResponse
    {
        if (request()->has('error')) {
            abort(400, 'Bad request');
        }

        if (request()->has('not_found')) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (request()->has('created')) {
            return response()->json(['message' => 'Created'], 201);
        }

        return response()->json(['message' => 'Success'], 200);
    }

    public function withAbortCalls(): JsonResponse
    {
        if (! auth()->check()) {
            abort(401, 'Unauthorized');
        }

        if (! auth()->user()->can('view')) {
            abort(403, 'Forbidden');
        }

        return response()->json(['data' => 'success']);
    }

    public function withHelperMethods(): JsonResponse
    {
        if (request()->has('created')) {
            return response()->created();
        }

        if (request()->has('accepted')) {
            return response()->accepted();
        }

        if (request()->has('no_content')) {
            return response()->noContent();
        }

        return response()->json(['status' => 'ok']);
    }

    public function withCustomHelpers()
    {
        if (request()->has('no_content')) {
            return $this->returnNoContent();
        }

        if (request()->has('accepted')) {
            return $this->returnAccepted();
        }

        return response()->json(['message' => 'Default response']);
    }

    private function returnNoContent(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function returnAccepted(): JsonResponse
    {
        return response()->json(null, Response::HTTP_ACCEPTED);
    }
}
