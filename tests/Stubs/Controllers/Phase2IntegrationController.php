<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\Post;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Models\User;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\ConditionalPostResource;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\ErrorResource;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\PaginatedUserResource;

#[Tag('Phase2Integration')]
class Phase2IntegrationController
{
    #[Summary('Get paginated users')]
    #[Description('Returns a paginated list of users with optional posts count')]
    public function paginatedUsers(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->when($request->has('include_posts_count'), function ($query) {
                return $query->withCount('posts');
            })
            ->paginate(15);

        return PaginatedUserResource::collection($users);
    }

    #[Summary('Get conditional post data')]
    #[Description('Returns post data with conditional fields based on request parameters')]
    public function conditionalPost(Request $request, int $id): ConditionalPostResource
    {
        $post = Post::query()
            ->when($request->has('include_user'), function ($query) {
                return $query->with('user');
            })
            ->findOrFail($id);

        return new ConditionalPostResource($post);
    }

    #[Summary('Get posts with conditional fields')]
    #[Description('Returns posts collection with fields that appear conditionally')]
    public function conditionalPosts(Request $request): AnonymousResourceCollection
    {
        $posts = Post::query()
            ->when($request->has('include_user'), function ($query) {
                return $query->with('user');
            })
            ->when($request->has('status'), function ($query) use ($request) {
                return $query->where('status', $request->get('status'));
            })
            ->get();

        return ConditionalPostResource::collection($posts);
    }

    #[Summary('Handle validation error')]
    #[Description('Returns standardized error response for validation failures')]
    public function validationError(): JsonResponse
    {
        $error = (object) [
            'code' => 'VALIDATION_ERROR',
            'message' => 'The given data was invalid.',
            'details' => [
                'name' => ['The name field is required.'],
                'email' => ['The email field must be a valid email address.'],
            ],
        ];

        return response()->json(new ErrorResource($error), 422);
    }

    #[Summary('Handle not found error')]
    #[Description('Returns standardized error response for resource not found')]
    public function notFoundError(): JsonResponse
    {
        $error = (object) [
            'code' => 'RESOURCE_NOT_FOUND',
            'message' => 'The requested resource was not found.',
        ];

        return response()->json(new ErrorResource($error), 404);
    }

    #[Summary('Handle server error')]
    #[Description('Returns standardized error response for internal server errors')]
    public function serverError(): JsonResponse
    {
        $error = (object) [
            'code' => 'INTERNAL_SERVER_ERROR',
            'message' => 'An unexpected error occurred.',
            'details' => 'Please contact support if this issue persists.',
        ];

        return response()->json(new ErrorResource($error), 500);
    }

    #[Summary('Get custom paginated response')]
    #[Description('Returns custom pagination structure with metadata')]
    public function customPaginatedResponse(): JsonResponse
    {
        $data = collect(range(1, 50))->map(function ($i) {
            return [
                'id' => $i,
                'name' => "Item {$i}",
                'value' => rand(1, 100),
            ];
        });

        $paginator = new LengthAwarePaginator(
            $data->slice(0, 10)->values(),
            $data->count(),
            10,
            1,
            ['path' => request()->url()]
        );

        return response()->json([
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
