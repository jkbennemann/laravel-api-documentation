<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;

class ListBoxesController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['boxes' => []]);
    }
}
