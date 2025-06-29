<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;

class ValidationController
{
    #[Tag('Validation')]
    #[Summary('Store user data with validation')]
    #[Description('Stores user data after validating the input fields')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'age' => 'nullable|integer|min:18',
            'type' => 'required|in:admin,user,guest',
            'website' => 'nullable|url',
            'profile' => 'nullable|array',
            'profile.bio' => 'nullable|string|max:1000',
            'profile.social' => 'nullable|array',
        ]);

        return response()->json($validated);
    }

    #[Tag('Validation')]
    #[Summary('Complex nested validation example')]
    public function complexValidation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user' => 'required|array',
            'user.name' => 'required|string|max:255',
            'user.email' => 'required|email',
            'user.preferences' => 'nullable|array',
            'user.preferences.theme' => 'nullable|string|in:light,dark,system',
            'user.preferences.notifications' => 'nullable|boolean',
            'address' => 'nullable|array',
            'address.street' => 'required_with:address|string',
            'address.city' => 'required_with:address|string',
            'address.zip' => 'required_with:address|string',
            'address.country' => 'required_with:address|string',
        ]);

        return response()->json($validated);
    }

    #[Tag('Validation')]
    #[Summary('Validation with custom error response')]
    public function validationWithErrors(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'username' => 'required|string|min:3|max:20',
                'password' => 'required|string|min:8',
                'password_confirmation' => 'required|same:password',
            ]);

            return response()->json(['message' => 'Validation passed']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
