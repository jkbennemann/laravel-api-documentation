<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestMethodCallController
{
    public function withInteger(Request $request): JsonResponse
    {
        $page = $request->integer('page', 1);
        $limit = $request->integer('limit');

        return response()->json(['page' => $page, 'limit' => $limit]);
    }

    public function withBoolean(Request $request): JsonResponse
    {
        $active = $request->boolean('active');

        return response()->json(['active' => $active]);
    }

    public function withFloat(Request $request): JsonResponse
    {
        $latitude = $request->float('latitude');

        return response()->json(['latitude' => $latitude]);
    }

    public function withString(Request $request): JsonResponse
    {
        $name = $request->string('name');
        $filter = $request->str('filter');

        return response()->json(['name' => $name, 'filter' => $filter]);
    }

    public function withDate(Request $request): JsonResponse
    {
        $since = $request->date('since');

        return response()->json(['since' => $since]);
    }

    public function withCollect(Request $request): JsonResponse
    {
        $items = $request->collect('items');

        return response()->json(['items' => $items]);
    }

    public function postEndpoint(Request $request): JsonResponse
    {
        $page = $request->integer('page');

        return response()->json(['page' => $page]);
    }

    public function withPathParam(Request $request, int $id): JsonResponse
    {
        $format = $request->string('format');

        return response()->json(['id' => $id, 'format' => $format]);
    }
}
