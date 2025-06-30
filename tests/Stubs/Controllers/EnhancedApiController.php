<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use JkBennemann\LaravelApiDocumentation\Attributes\QueryParameter;
use JkBennemann\LaravelApiDocumentation\Attributes\RequestBody;
use JkBennemann\LaravelApiDocumentation\Attributes\ResponseBody;
use JkBennemann\LaravelApiDocumentation\Attributes\ResponseHeader;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\CreateUserData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\UserData;

class EnhancedApiController
{
    /**
     * Get users with enhanced parameter documentation
     *
     * @param  string  $search  Search query
     * @param  int  $page  Page number
     * @param  int  $per_page  Items per page
     */
    #[QueryParameter('search', 'Search users by name or email', 'string', required: false)]
    #[QueryParameter('page', 'Page number for pagination', 'integer', required: false, example: 1)]
    #[QueryParameter('per_page', 'Number of items per page', 'integer', required: false, example: 10)]
    #[QueryParameter('status', 'Filter by user status', 'string', required: false, enum: ['active', 'inactive', 'pending'])]
    #[ResponseBody(200, 'List of users', 'application/json', UserData::class, isCollection: true)]
    #[ResponseHeader('X-Total-Count', 'Total number of users', 'integer')]
    #[ResponseHeader('X-Page-Count', 'Total number of pages', 'integer')]
    public function index()
    {
        // Implementation would be here
        return response()->json([]);
    }

    /**
     * Create a new user with Spatie Data request body
     */
    #[RequestBody('User creation data', 'application/json', true, CreateUserData::class)]
    #[ResponseBody(201, 'User created successfully', 'application/json', UserData::class)]
    #[ResponseBody(422, 'Validation errors', 'application/json')]
    #[ResponseHeader('Location', 'URL of the created user', 'string')]
    public function store()
    {
        // Implementation would be here
        return response()->json([]);
    }

    /**
     * Get a specific user
     */
    #[ResponseBody(200, 'User details', 'application/json', UserData::class)]
    #[ResponseBody(404, 'User not found', 'application/json')]
    public function show(int $id)
    {
        // Implementation would be here
        return response()->json([]);
    }

    /**
     * Update a user
     */
    #[RequestBody('User update data', 'application/json', true, CreateUserData::class)]
    #[ResponseBody(200, 'User updated successfully', 'application/json', UserData::class)]
    #[ResponseBody(404, 'User not found', 'application/json')]
    #[ResponseBody(422, 'Validation errors', 'application/json')]
    public function update(int $id)
    {
        // Implementation would be here
        return response()->json([]);
    }
}
