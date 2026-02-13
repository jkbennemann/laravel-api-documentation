<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\SimpleRequest;

class ContainerResolvedController
{
    public function storeWithResolve(Request $request): JsonResponse
    {
        $validated = resolve(SimpleRequest::class);

        return response()->json($validated->validated());
    }

    public function storeWithApp(Request $request): JsonResponse
    {
        $validated = app(SimpleRequest::class);

        return response()->json($validated->validated());
    }

    public function storeViaHelper(Request $request): JsonResponse
    {
        $validated = $this->validateRequest();

        return response()->json($validated);
    }

    public function storeViaNested(Request $request): JsonResponse
    {
        $validated = $this->validateNestedRequest();

        return response()->json($validated);
    }

    protected function validateRequest(): array
    {
        $request = resolve(SimpleRequest::class);

        return $request->validated();
    }

    protected function validateNestedRequest(): array
    {
        $request = $this->getRequestValidator();

        return $request->validated();
    }

    public function getRequestValidator(): SimpleRequest
    {
        return resolve(SimpleRequest::class);
    }

    public function abortOnly(): JsonResponse
    {
        abort(405);
    }

    public function abortNotImplemented(): JsonResponse
    {
        abort(501);
    }
}
