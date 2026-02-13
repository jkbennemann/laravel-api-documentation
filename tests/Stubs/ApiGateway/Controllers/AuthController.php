<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\DocumentationFile;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\ApiGateway\Requests\LoginRequest;

#[DocumentationFile('public-api')]
class AuthController extends Controller
{
    #[Tag('Authentication')]
    #[Summary('Login')]
    #[DataResponse(200, resource: [
        'access_token' => 'string',
        'token_type' => 'string',
        'expires_in' => 'integer',
    ], description: 'Login successful', headers: ['access_token' => 'JWT token'])]
    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json([]);
    }
}
