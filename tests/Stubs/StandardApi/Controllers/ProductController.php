<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers;

use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Product;

class ProductController
{
    public function show(Product $product): JsonResponse
    {
        return response()->json($product);
    }
}
