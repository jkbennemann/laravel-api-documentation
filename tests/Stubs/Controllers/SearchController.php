<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\SearchRequest;

class SearchController
{
    public function index(SearchRequest $request): JsonResponse
    {
        return response()->json(['results' => []]);
    }
}
