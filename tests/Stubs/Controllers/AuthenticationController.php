<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\Response;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\LoginData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Requests\PostLoginRequest;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Throwable;

class AuthenticationController
{
    #[Tag('Authentication')]
    #[Summary('Login an user by credentials')]
    #[DataResponse(status: ResponseAlias::HTTP_OK, description: 'Successful login', resource: LoginData::class, headers: ['access_token' => 'JWT token for the requested user'])]
    public function login(PostLoginRequest $request): Response
    {
        try {
            // Simulate the login process
            $dto = new LoginData(
                id: '2Q1DG07Z',
                email: 'user@example.com',
                trashboardId: 13804,
                attributes: [
                    ['name' => 'external_identifier', 'data' => null, 'value' => 'RB123456']
                ],
                roles: [
                    ['role' => 'SUPER_ADMIN', 'expires_at' => null]
                ]
            );
            
            // Return the response with headers
            return new Response(
                $dto,
                ResponseAlias::HTTP_OK,
                ['access_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...']
            );
        } catch (Throwable $exception) {
            report($exception);
            throw $exception;
        }
    }
}
