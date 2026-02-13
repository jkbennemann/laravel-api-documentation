<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;

class TagDescriptionController
{
    #[Tag('Widgets', description: 'Widget management operations')]
    public function index(): JsonResponse
    {
        return response()->json([]);
    }
}
