<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\UserResource;

class SmartController extends Controller
{
    public function storeWithValidation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        return response()->json(['message' => 'User created']);
    }

    public function index(): Collection
    {
        return collect([
            ['id' => 1, 'name' => 'John Doe'],
            ['id' => 2, 'name' => 'Jane Doe'],
        ]);
    }

    public function paginated(): ResourceCollection
    {
        $users = collect([
            ['id' => 1, 'name' => 'John Doe'],
            ['id' => 2, 'name' => 'Jane Doe'],
        ])->paginate();

        return UserResource::collection($users);
    }

    public function errorResponse(Request $request): JsonResponse
    {
        throw ValidationException::withMessages([
            'email' => ['The email field is required.'],
        ]);
    }

    public function show(int $user): JsonResponse
    {
        return response()->json(['id' => $user, 'name' => 'John Doe']);
    }
}
