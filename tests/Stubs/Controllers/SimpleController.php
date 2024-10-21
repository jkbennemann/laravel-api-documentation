<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SimpleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Hello World!',
        ]);
    }
}
