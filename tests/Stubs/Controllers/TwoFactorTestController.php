<?php

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\Response;

class TwoFactorTestController
{
    public function enable(): Response
    {
        return response()
            ->noContent(204);
    }

    public function confirm(): Response
    {
        return response()
            ->noContent(204);
    }

    public function login(): Response
    {
        return response()->json([
            'access_token' => 'jwt_token_here',
            'user_id' => '123',
        ], 200);
    }
}
