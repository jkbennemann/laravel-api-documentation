<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\Simple;

class DtoResponseController extends Controller
{
    #[DataResponse(status: 200, description: 'A sample description', resource: Simple::class)]
    public function simple(): JsonResponse
    {
        $dto = new Simple('John', 42);

        return response()->json($dto);
    }
}
