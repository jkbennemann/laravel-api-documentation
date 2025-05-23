
declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\AdvancedUserData;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\SimpleAnnotated;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs\UserData;

class SmartController
{
    /**
     * @queryParam page int Page number for pagination
     * @queryParam per_page int Number of items per page
     *
     * @return ResourceCollection
     */
    public function index(Request $request)
    {
        // Return a collection of users
        return new ResourceCollection([]);
    }

    /**
     * @queryParam search string Search term for filtering users
     * @queryParam status string Filter by user status
     */
    public function paginated(Request $request)
    {
        // Return paginated results
        return new LengthAwarePaginator([], 0, 15, 1);
    }

    public function storeWithValidation(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ]);

        return response()->json(['message' => 'User created']);
    }

    public function errorResponse(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return response()->json(['message' => 'Success']);
    }

    public function show($user)
    {
        return response()->json(['id' => $user, 'name' => 'Test User']);
    }

    /**
     * Get a user with dynamic response type
     *
     * @param int $userId
     * @param string $format Response format (simple or advanced)
     * @return UserData|AdvancedUserData
     */
    public function getUserWithDynamicType(int $userId, string $format = 'simple')
    {
        if ($format === 'advanced') {
            return new AdvancedUserData(
                id: $userId,
                name: 'Test User',
                email: 'test@example.com',
                role: 'admin',
                permissions: ['create', 'read', 'update', 'delete']
            );
        }

        return new UserData(
            id: $userId,
            name: 'Test User',
            email: 'test@example.com'
        );
    }

    /**
     * Store user with nested Spatie Data objects
     *
     * @param Request $request
     * @return SimpleAnnotated
     */
    public function storeNestedData(Request $request)
    {
        return new SimpleAnnotated();
    }
}
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
