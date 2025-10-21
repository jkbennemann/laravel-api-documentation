# Laravel API Documentation

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jkbennemann/laravel-api-documentation.svg?style=flat-square)](https://packagist.org/packages/jkbennemann/laravel-api-documentation)
[![Total Downloads](https://img.shields.io/packagist/dt/jkbennemann/laravel-api-documentation.svg?style=flat-square)](https://packagist.org/packages/jkbennemann/laravel-api-documentation)

## Overview

Laravel API Documentation is a powerful package that automatically generates OpenAPI 3.0 documentation from your Laravel application code. It eliminates the need to manually write and maintain API documentation by intelligently analyzing your routes, controllers, requests, and responses.

### Key Features

- **🎯 Runtime Response Capture**: Achieve 95%+ accuracy by capturing real API responses during testing (NEW!)
- **Zero-Config Operation**: Works out of the box with standard Laravel conventions
- **Automatic Route Analysis**: Scans all routes and extracts path parameters, HTTP methods, and middleware
- **Smart Request Analysis**: Extracts validation rules from FormRequest classes to document request parameters
- **Dynamic Response Detection**: Analyzes controller return types and method bodies to document responses
- **Spatie Data Integration**: First-class support for Spatie Laravel Data DTOs
- **Resource Collection Support**: Handles JsonResource and ResourceCollection responses
- **Attribute Enhancement**: Optional PHP 8 attributes for additional documentation control
- **🔒 Production Safe**: Zero runtime overhead - capture only runs in local/testing environments

## Installation

### 1. Install via Composer

```bash
composer require jkbennemann/laravel-api-documentation
```

### 2. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="api-documentation-config"
```

### 3. Link Storage Directory

The package stores documentation in your storage directory. Make it accessible with:

```bash
php artisan storage:link
```

## Configuration

### Default Settings

Out of the box, the package:

- Ignores vendor routes and `HEAD`/`OPTIONS` methods
- Disables Swagger/ReDoc UIs by default (can be enabled in config)
- Stores documentation at `storage/app/public/api-documentation.json`

### Documentation Storage

To include the generated documentation in version control, update your `.gitignore`:

```bash
# storage/app/public/.gitignore
*
!.gitignore
!api-documentation.json
```

### Custom Storage Location

For a more accessible location, add a custom disk in `config/filesystems.php`:

```php
'documentation' => [
    'driver'     => 'local',
    'root'       => public_path('docs'),
    'url'        => env('APP_URL') . '/docs',
    'visibility' => 'public',
],
```

Then update your config:

```php
// config/laravel-api-documentation.php
'storage' => [
    'disk' => 'documentation',
    'filename' => 'api-documentation.json',
],
```

### CI/CD Integration

Add documentation generation to your deployment workflow:

```bash
# In your deployment script
php artisan documentation:generate
```

Or add to your `composer.json` scripts:

```json
"scripts": {
    "post-deploy": [
        "@php artisan documentation:generate"
    ]
}
```

## Usage

### Quick Start with Runtime Capture (Recommended)

For **95%+ accuracy**, enable runtime capture to use real API responses:

```bash
# 1. Enable capture in .env.local
DOC_CAPTURE_MODE=true

# 2. Run your tests (responses are automatically captured)
composer test

# 3. Generate documentation (uses captured + static analysis)
php artisan documentation:generate
```

**That's it!** Your documentation now reflects actual API behavior with 95%+ accuracy.

See [Runtime Capture Guide](RUNTIME_CAPTURE_GUIDE.md) for detailed information.

### Basic Generation (Static Analysis Only)

```bash
php artisan documentation:generate
```

This command scans your application routes and generates an OpenAPI 3.0 specification file at your configured location using static code analysis (~70% accuracy).

### Viewing Documentation

By default, the documentation is accessible at:

- `/documentation` - Default UI (Swagger if enabled)
- `/documentation/swagger` - Swagger UI (if enabled)
- `/documentation/redoc` - ReDoc UI (if enabled)

To enable the UIs, update your configuration:

```php
// config/laravel-api-documentation.php
'ui' => [
    'enabled' => true,
    'swagger' => true,
    'redoc' => true,
],
```

### Specifying Files to Generate

Generate documentation for specific files only:

```bash
php artisan documentation:generate --file=api-v1
```

This generates `api-v1.json` based on your configuration settings.

## How It Works

The package analyzes your Laravel application using several intelligent components:

1. **Route Analysis**: Scans all registered routes to identify controllers, HTTP methods, and path parameters
2. **Controller Analysis**: Examines controller methods to determine response types and structures
3. **Request Analysis**: Processes FormRequest classes to extract validation rules and convert them to OpenAPI parameters
4. **Response Analysis**: Detects return types and analyzes method bodies to determine response structures

## Key Features

### Zero-Configuration Detection

The package automatically detects and documents your API with minimal configuration:

#### Response Type Detection
- Analyzes controller return types (`JsonResponse`, `ResourceCollection`, etc.)
- Examines method bodies when return types aren't declared
- Supports union types (`@return UserData|AdminData`)
- Generates proper paginated response structures with `data`, `meta`, and `links`

#### Controller Support
- Works with traditional and invokable controllers
- Processes class-level and method-level attributes
- Handles mixed controller architectures seamlessly

#### Request Parameter Extraction
- Extracts validation rules from FormRequest classes
- Detects route parameters (`{id}`, `{user}`) automatically
- Supports nested parameter structures (`user.profile.name`)
- Handles parameters merged from route values in `prepareForValidation`

#### Validation & Type Detection
- Converts Laravel validation rules to OpenAPI types and formats
- Intelligently determines required vs. optional parameters
- Maps validation rules to appropriate formats (`email` → `string` with `email` format)

#### Resource & Collection Support
- Distinguishes between arrays and object responses
- Analyzes ResourceCollection for contained DTO types
- Supports `DataCollectionOf` attributes for nested collections

## Recent Enhancements

### Route Parameter Handling
- **Route Value Detection**: Properly handles parameters merged from route values in `prepareForValidation`
- **Parameter Exclusion**: Supports the `IgnoreDataParameter` attribute to exclude fields from body parameters

### Spatie Data Integration
- **Clean Schema Generation**: Excludes internal Spatie Data fields (`_additional` and `_data_context`) from documentation
- **DataCollectionOf Support**: Properly documents nested collection structures with correct item types
- **Union Type Support**: Handles PHP 8+ union types in Spatie Data objects

### Dynamic Response Analysis
- **JsonResource Analysis**: Improved detection of dynamic properties in JsonResource responses
- **Method Body Parsing**: Enhanced analysis of controller method bodies for response structure detection
- **Paginated Response Support**: Accurate documentation of paginated responses with proper structure

---

For more information on the OpenAPI specification, see [OpenAPI Specification](https://swagger.io/specification/).

## Enhancing Documentation with Attributes

While the package works automatically, you can enhance your documentation using PHP 8 attributes.

### Controller Method Attributes

```php
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\AdditionalDocumentation;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;

#[Tag('Authentication')]
#[Summary('Login a user')]
#[Description('Logs a user in with email and password credentials.')]
#[AdditionalDocumentation(url: 'https://example.com/auth', description: 'Auth documentation')]
#[DataResponse(200, description: 'Logged in user information', resource: UserResource::class)]
#[DataResponse(401, description: 'Failed Authentication', resource: ['error' => 'string'])]
public function login(LoginRequest $request)
{
    // Method implementation
}
```

Available parameters:
- (required) `status` (int) - The status code of the response
- `description` (string) - A description of the response; *default:`''`*
- `resource` (null | string | array) - The resource class or Spatie Data object class that is returned by the route (e.g. `UserResource::class`, `['id' => 'string']`, `null`); *default:`[]`*
- `headers` (null | array) - An array of response headers that are returned by the route (e.g. `['X-Token' => 'string']`); *default:`[]`*

```php
# SampleController.php
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;

//..
#[DataResponse(200, description: 'Logged in user information', resource: UserResource::class, headers: ['X-Token' => 'Token for the user to be used to issue API calls',])]
#[DataResponse(401, description: 'Failed Authentication', resource: ['error' => 'string'])]
public function index()
{
    //...
}
```
This will add a new field to the route object in the OpenAPI file:
```json
{
    //...
    "\/login": {
        "post": {
            //...
            "responses": {
                "200": {
                    "description": "Logged in user information",
                    "headers": {
                        "X-Token": {
                            "description": "Token for the user to be used to issue API calls",
                            "schema": {
                                "type": "string"
                            }
                        }
                    },
                    "content": {
                        "application\/json": {
                            "schema": {
                                //data of the UserResource.php
                            }
                        }
                    }
                },
                "401": {
                    "description": "Failed Authentication",
                    "headers": {},
                    "content": {
                        "application\/json": {
                            "schema": {
                                "type": "object",
                                "properties": {
                                    "error": {
                                        "type": "string"
                                    }
                                }
                            }
                        }
                    }
                }
            }
            //...
        }
    }
}
```

### Request/Resource attributes

For request or resource classes the same attributes can be used as for the routes, except for the `Tag` attribute.  
In addition to that the following attributes are available:

#### 1. PathParameter
This attribute can be used to specify additional information about path parameters in your routes.  
It can be applied to controller methods to enhance the documentation of route parameters like `{id}`, `{user}`, etc.

Available parameters:
- (required) `name` (string) - The name of the path parameter (must match the route parameter)
- `required` (boolean) - Whether the parameter is required or not; *default:`true`*
- `description` (string) - A description of the parameter; *default:`''`*
- `type` (string) - The type of the parameter; *default:`'string'`*
- `format` (string) - The format of the parameter, considered as the sub-type as of OpenAPI; *default:`null`*
- `example` (mixed) - An example value for the parameter; *default:`null`*

```php
# UserController.php
use JkBennemann\LaravelApiDocumentation\Attributes\PathParameter;

//..
#[PathParameter(name: 'id', type: 'string', format: 'uuid', description: 'The user ID', example: '123e4567-e89b-12d3-a456-426614174000')]
#[PathParameter(name: 'status', type: 'string', description: 'Filter users by status', example: 'active')]
public function show(string $id, string $status)
{
    //...
}
```

#### 2. Parameter
This attribute can be used to specify additional information about request or response parameters.  
It can be applied at both **class level** and **method level** (e.g., on the `rules()` method of FormRequest classes).

Available parameters:
- (required) `name` (string) - The name of the parameter
- `required` (boolean) - Whether the parameter is required or not; *default:`false`*
- `description` (string) - A description of the parameter; *default:`''`*
- `type` (string) - The type of the parameter; *default:`'string'`*
- `format` (string) - The format of the parameter, considered as the sub-type as of OpenAPI; *default:`null`*
- `example` (mixed) - An example value for the parameter; *default:`null`*
- `deprecated` (boolean) - Whether the parameter is deprecated or not; *default:`false`*

**Method Level Usage (FormRequest):**
```php
# LoginUserRequest.php
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

//..
#[Parameter(name: 'email', required: true, format: 'email', description: 'The email of the user', example: 'hello@example.com')]
#[Parameter(name: 'password', required: true, description: 'The password of the user')]
#[Parameter(name: 'confirm_token', required: true, description: 'The confirmation token. This is not used any longer!', deprecated: true)]
public function rules(): array
{
    return [
        'email' => [
            'required',
            'email',
        ],
        'password' => 'required',
    ];
}
```

**Class Level Usage (Resource):**
```php
# UserResource.php
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

#[Parameter(name: 'id', type: 'string', format: 'uuid', description: 'The user ID', example: '123e4567-e89b-12d3-a456-426614174000')]
#[Parameter(name: 'email', type: 'string', format: 'email', description: 'The users email address')]
#[Parameter(name: 'attributes', type: 'array', description: 'Additional attributes assigned to the user', example: [])]
public function toArray($request): array|JsonSerializable|Arrayable
{
    return [
        'id' => $this->id,
        'email' => $this->email,
        'attributes' => $this->userAttributes ?? [],
    ];
}
```

### 📋 **Query Parameter Annotations**

In addition to attributes, the package supports `@queryParam` annotations for documenting query parameters:

```php
# UserController.php

/**
 * Get a list of users
 * 
 * @queryParam per_page integer Number of users per page. Example: 15
 * @queryParam search string Search term for filtering users. Example: john
 * @queryParam status string Filter by user status. Example: active
 */
public function index(Request $request)
{
    //...
}
```

This automatically generates query parameter documentation in the OpenAPI specification.

## 💡 **Usage Examples & Best Practices**

Here are some comprehensive examples showing how to leverage the automatic detection features:

### **Example 1: Complete Controller with Automatic Detection**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use JkBennemann\LaravelApiDocumentation\Attributes\{Tag, Summary, Description, PathParameter, DataResponse};

#[Tag('Users')]
class UserController extends Controller
{
    /**
     * Get a paginated list of users
     * 
     * @queryParam per_page integer Number of users per page. Example: 15
     * @queryParam search string Search term for filtering users. Example: john
     * @queryParam status string Filter by user status. Example: active
     * 
     * @return ResourceCollection<UserResource>
     */
    #[Summary('List all users')]
    #[Description('Retrieves a paginated list of users with optional filtering capabilities')]
    public function index(Request $request): ResourceCollection
    {
        $users = User::query()
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->paginate($request->per_page ?? 15);

        return UserResource::collection($users);
    }

    /**
     * Get a specific user by ID
     */
    #[PathParameter(name: 'id', type: 'string', format: 'uuid', description: 'The user ID', example: '123e4567-e89b-12d3-a456-426614174000')]
    #[DataResponse(200, description: 'User found successfully', resource: UserResource::class)]
    #[DataResponse(404, description: 'User not found', resource: ['message' => 'string'])]
    public function show(string $id): UserResource|JsonResponse
    {
        $user = User::findOrFail($id);
        return new UserResource($user);
    }

    /**
     * Create a new user
     */
    #[Summary('Create user')]
    #[DataResponse(201, description: 'User created successfully', resource: UserResource::class)]
    #[DataResponse(422, description: 'Validation failed')]
    public function store(CreateUserRequest $request): UserResource
    {
        $user = User::create($request->validated());
        return new UserResource($user);
    }
}
```

### **Example 2: Advanced FormRequest with Nested Parameters**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

class CreateUserRequest extends FormRequest
{
    #[Parameter(name: 'name', required: true, description: 'The full name of the user', example: 'John Doe')]
    #[Parameter(name: 'email', required: true, format: 'email', description: 'Unique email address', example: 'john@example.com')]
    #[Parameter(name: 'profile.bio', description: 'User biography', example: 'Software developer with 5 years experience')]
    #[Parameter(name: 'profile.avatar', type: 'string', format: 'uri', description: 'Avatar image URL')]
    #[Parameter(name: 'preferences.notifications', type: 'boolean', description: 'Enable email notifications', example: true)]
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'profile.bio' => ['nullable', 'string', 'max:1000'],
            'profile.avatar' => ['nullable', 'url'],
            'preferences.notifications' => ['boolean'],
            'preferences.theme' => ['string', 'in:light,dark'],
        ];
    }
}
```

### **Example 3: Invokable Controllers with Class-Level Attributes**

Laravel's invokable controllers (single-action controllers) are fully supported with class-level attribute processing:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\{Tag, Summary, Description, DataResponse};

#[Tag('Posts')]
#[Summary('Create a new blog post')]
#[Description('Creates a new blog post with the provided content and metadata')]
#[DataResponse(PostResource::class, 201, 'Post created successfully')]
class CreatePostController extends Controller
{
    /**
     * Handle the incoming request to create a new post.
     * 
     * @param CreatePostRequest $request
     * @return JsonResponse
     */
    public function __invoke(CreatePostRequest $request): JsonResponse
    {
        $post = Post::create($request->validated());
        
        return PostResource::make($post)
            ->response()
            ->setStatusCode(201);
    }
}
```

**Key Features for Invokable Controllers:**
- **Class-Level Attributes**: `#[Tag]`, `#[Summary]`, `#[Description]` placed on the class are automatically detected
- **Automatic Route Processing**: Both `Controller@method` and `Controller` (invokable) route formats supported
- **Response Type Detection**: Same automatic detection as traditional controllers
- **Request Validation**: FormRequest classes processed normally
- **Mixed Controller Support**: Traditional and invokable controllers work seamlessly together

**Route Registration:**
```php
// Traditional route registration for invokable controllers
Route::post('/posts', CreatePostController::class);

// Mixed with traditional controllers
Route::get('/posts', [PostController::class, 'index']);
Route::post('/posts', CreatePostController::class);  // Invokable
Route::get('/posts/{id}', [PostController::class, 'show']);
```

### **Example 4: Resource with Spatie Data Integration**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

class UserResource extends JsonResource
{
    #[Parameter(name: 'id', type: 'string', format: 'uuid', description: 'Unique user identifier')]
    #[Parameter(name: 'name', type: 'string', description: 'Full name of the user')]
    #[Parameter(name: 'email', type: 'string', format: 'email', description: 'User email address')]
    #[Parameter(name: 'profile', type: 'object', description: 'User profile information')]
    #[Parameter(name: 'created_at', type: 'string', format: 'date-time', description: 'Account creation timestamp')]
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'profile' => [
                'bio' => $this->profile?->bio,
                'avatar' => $this->profile?->avatar,
            ],
            'preferences' => [
                'notifications' => $this->preferences['notifications'] ?? true,
                'theme' => $this->preferences['theme'] ?? 'light',
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

### **Example 5: Union Types with Multiple Response Formats**

```php
<?php

namespace App\Http\Controllers;

use App\DTOs\UserData;
use App\DTOs\AdminData;
```

### **🎯 Best Practices**

1. **Leverage Automatic Detection**: Let the package automatically detect response types and validation rules
2. **Use Attributes for Enhancement**: Add `#[Parameter]` and `#[PathParameter]` attributes only when you need to provide additional context
3. **Document Complex Scenarios**: Use `@queryParam` annotations for query parameters and union return types for multiple response formats
4. **Structured Validation**: Use nested validation rules for complex request structures
5. **Consistent Naming**: Use consistent parameter naming across your API for better documentation

## Roadmap

### ✅ **Completed Features**
- [x] **Advanced Response Type Detection**: Automatic detection of return types, union types, and method body analysis
- [x] **Smart Request Parameter Extraction**: FormRequest validation rule processing and parameter attribute support
- [x] **Invokable Controller Support**: Full support for Laravel invokable controllers with class-level attribute processing
- [x] **Path Parameter Documentation**: `#[PathParameter]` attribute for route parameter enhancement
- [x] **Query Parameter Support**: `@queryParam` annotation processing
- [x] **Nested Parameter Handling**: Complex nested parameter structures with proper grouping
- [x] **Spatie Data Integration**: Full support for Spatie Data objects with automatic schema generation
- [x] **Resource Collection Intelligence**: Smart detection of collection types and pagination
- [x] **Union Type Processing**: DocBlock union type analysis with `oneOf` schema generation
- [x] **Class Name Resolution**: Automatic resolution of short class names to fully qualified names
- [x] **Validation Rule Conversion**: Laravel validation rules to OpenAPI type conversion

### 🚀 **Planned Enhancements**
- [ ] **Enhanced Examples**: More comprehensive example generation for request/response bodies
- [ ] **Custom Validation Rules**: Support for custom Laravel validation rules
- [ ] **Advanced Response Headers**: Automatic detection of response headers from controller methods
- [ ] **File Upload Documentation**: Automatic detection and documentation of file upload endpoints
- [ ] **Middleware Documentation**: Enhanced middleware detection and security scheme generation
- [ ] **Custom Storage Options**: Support for external storages (e.g., GitHub, S3 Bucket, etc.)
- [ ] **Multi-version API Support**: Support for API versioning in documentation
- [ ] **Performance Optimization**: Caching mechanisms for large applications
- [ ] **Integration Testing**: Built-in API testing capabilities based on generated documentation

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jakob Bennemann](https://github.com/jkbennemann)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
