<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;

class SearchController
{
    /**
     * Search for items.
     *
     * @param  string  $query  The search query string
     * @param  int  $limit  Maximum number of results
     * @param  bool  $include_archived  Include archived items
     */
    public function search(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * No PHPDoc params here.
     */
    public function noParams(): JsonResponse
    {
        return response()->json([]);
    }
}
