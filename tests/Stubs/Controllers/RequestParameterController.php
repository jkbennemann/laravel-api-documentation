<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\SimpleRequest;

class RequestParameterController extends Controller
{
    public function index(SimpleRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Hello World!',
        ]);
    }
}
