# Laravel API documentation

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jkbennemann/laravel-api-documentation.svg?style=flat-square)](https://packagist.org/packages/jkbennemann/laravel-api-documentation)
[![Total Downloads](https://img.shields.io/packagist/dt/jkbennemann/laravel-api-documentation.svg?style=flat-square)](https://packagist.org/packages/jkbennemann/laravel-api-documentation)

> **This library is currently in an alpha phase**  
> - still under development
> - versions are not stable
> - breaking changes can occur


This is an opinionated package to generate API documentation for Laravel applications.
They key focus is to remove the need to manually write documentation and keep it up to date by generating it from the code.

## Installation

You can install the package via composer:

```bash
composer require jkbennemann/laravel-api-documentation
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-api-documentation-config"
```
By default it will set the settings to the following:

- Ignore all routes that are registered by a vendor package
- Ignore all routes with HTTP methods `HEAD`, `OPTIONS`
- UIs for swagger or redoc are disabled
- If enabled the UIs are available at
  - `/documentation` (defaults to swagger)
  - `/documentation/redoc` (if enabled)
  - `/documentation/swagger` (if enabled)

### Linking the storage's public directory

The package will store the generated documentation at the defined disk.  
By default it will use the `public` disk to store the documentation.
For the default Laravel disk of `public` you'd need to link the storage folder to make it accessible by running:

```bash
php artisan storage:link
```

This will ensure that the generated documentation is accessible.  
When generating the documentation the package (by default) will store the documentation at `storage/app/public/api-documentation.json`.

In order for this to work you need to have the `public` disk configured in your `config/filesystems.php`.  
This should be already configured by default by your application.
```php
# config/filesystems.php

//...
'public' => [
    'driver' => 'local',
    'root' => storage_path('app/public'),
    'url' => env('APP_URL') . '/storage',
    'visibility' => 'public',
    'throw' => false,
],
//...
```

**As the documentation is stored in the `storage` folder of your application which by default will not be added to the VCS  
it is needed to explicitly exclude the `api-documentation.json` from your `storage/app/public/.gitignore` file.**

```bash
# storage/app/public/.gitignore
*
!.gitignore
!api-documentation.json
```

If you want to make the documentation public by default, without the need to link the storage folder, you can:
1. Add a dedicated disk configuration for the documentation to `config/filesystems.php` *(Recommended)*
2. Adjust the default disk configuration to not use the `storage_path()` *(Not recommended)*
```php
'public_exposed' => [
    'driver'     => 'local',
    'root'       => public_path(),
    'url'        => env('APP_URL'),
    'visibility' => 'public',
    'throw'      => false,
],
```
and then using the disk `public_exposed` in the `config/laravel-api-documentation.php` file.

### Useful tips
If you want to automatically re-generate the documentation all the time, consider to add a git hook to your repository.  
This way you allow the documentation to be kept up-to-date, every time you push changes to your repository.

## Generating the documentation
```bash
Generating the documentation is as simple as running the following command:
```bash
php artisan documentation:generate
```

In case you want to be able to let your application generate the documentation through composer scripts  
you can add the following to your `composer.json`:
```json
{
    "scripts": {
        "documentation:generate": [
            "@php artisan documentation:generate"
        ]
    }
}
```


## How it works
The package will scan all routes of your application and generate an OpenAPI file containing the documentation.

Under the hood the package utilizes the PHP Parser to parse the routes and their controllers.  
It then walks through Request and Response classes to figure out the structure of the request and response objects.

### 🚀 **Automatic Detection Features**

The package includes powerful automatic detection capabilities that require **no manual annotations** in most cases:

#### **1. Intelligent Response Type Detection**
- **Return Type Analysis**: Automatically detects controller method return types (`JsonResponse`, `ResourceCollection`, `JsonResource`, etc.)
- **Method Body Analysis**: When no return type is declared, analyzes method content to detect response patterns
- **Union Type Support**: Handles union types from docblocks (e.g., `@return UserData|AdminData`)
- **Spatie Data Integration**: Seamlessly generates schemas for Spatie Data objects
- **Paginated Response Detection**: Automatically detects `LengthAwarePaginator` usage and generates proper paginated response structure with `data`, `meta`, and `links` properties

#### **2. Comprehensive Controller Support**
- **Traditional Controllers**: Full support for standard Laravel controllers with method-based routes
- **Invokable Controllers**: Complete support for single-action controllers with `__invoke()` method
- **Class-Level Attributes**: Automatic detection of attributes placed on controller classes (essential for invokable controllers)
- **Mixed Applications**: Seamless handling of applications using both traditional and invokable controllers

#### **3. Smart Request Parameter Extraction**
- **FormRequest Analysis**: Automatically extracts validation rules from FormRequest classes
- **Query Parameter Detection**: Analyzes `@queryParam` annotations and generates query parameter schemas
- **Path Parameter Processing**: Detects route parameters (`{id}`, `{user}`) and generates appropriate documentation
- **Nested Parameter Support**: Handles complex nested parameter structures (e.g., `user.profile.name`)

#### **4. Advanced Validation Detection**
- **Rule Processing**: Converts Laravel validation rules to OpenAPI types and formats
- **Required Field Detection**: Automatically determines required vs optional parameters
- **Type Inference**: Smart type detection from validation rules (`email` → `string` with `email` format)

#### **5. Resource Collection Intelligence**
- **Array vs Object Detection**: Distinguishes between generic arrays and specific resource objects
- **Collection Type Analysis**: Automatically determines if ResourceCollection contains specific DTOs or generic data
- **Pagination Support**: Generates proper schemas for paginated collections

### 🎯 **Automatic Generation Rules**

The package follows these intelligent rules for automatic documentation generation:

1. **Response Type Priority**:
   - First: Explicit return type declarations
   - Second: Method body pattern analysis (e.g., `new LengthAwarePaginator()`)
   - Third: Docblock analysis (e.g., `@return UserData`)
   - Fourth: Default fallback to generic response

2. **Parameter Detection Order**:
   - Path parameters from route definitions (`{id}`)
   - Request parameters from FormRequest validation rules
   - Query parameters from `@queryParam` annotations
   - Enhanced with `#[Parameter]` attributes when provided

3. **Schema Generation Logic**:
   - Spatie Data objects: Generate comprehensive property schemas
   - Laravel Resources: Analyze `toArray()` method structure
   - Union types: Create `oneOf` schemas with multiple options
   - Collections: Generate array schemas with proper item definitions

4. **Class Resolution**:
   - Automatic resolution of short class names to fully qualified names
   - Parsing of `use` statements for proper namespace resolution
   - Smart handling of relative class references

With PHP 8 attributes it is possible to add additional information to the routes which will enhance the generated documentation.  
These attributes are defined by the package and can be used for Controller methods (traditional controllers), Controller classes (invokable controllers), Request classes and Response classes.

In addition to that we will also include the authentication middleware of a route if it is defined in the route and add it to the OpenAPI file for each route.

The package will then generate an OpenAPI file containing the documentation.

---

*The functionality is still limited and will not cover all scenarios you need to be covered.  
Feel free to open an issue or PR if you have a feature request or found a bug.*  
<br>
See the [Roadmap](#roadmap) section for more information on planned feature updates.  
For more information on the OpenAPI specification see [OpenAPI Specification](https://swagger.io/specification/).

## Available attributes

### Route attributes

#### 1. Tag
This attribute can be used to add the route to a specific tag.  
*Multiple tags can be added to a route, which will however add them multiple times to the OpenAPI file!*

```php
# SampleLoginController.php
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;

//..
#[Tag('Authentication')]
public function login()
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
            "tags": [
                "Authentication"
            ],
            //...
        }
    }
}
```

#### 2. Summary
This attribute can be used to add a summary to the route.

```php
# SampleLoginController.php
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;

//..
#[Summary('Login a user')]
public function login()
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
            "summary": "Login a user",
            //...
        }
    }
}
```


#### 3. Description
This attribute can be used to add a more detailed description to the route.  
This attribute also supports HTML.

```php
# SampleLoginController.php
use JkBennemann\LaravelApiDocumentation\Attributes\Description;

//..
#[Description('Logs an user in. <br> This route requires a valid email and password.')]
public function login()
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
            "description": "Logs an user in. <br> This route requires a valid email and password.",
            //...
        }
    }
}
```


#### 4. AdditionalDocumentation
This attribute can be used to add additional documentation to the route which are pointing to any external resources.
```php
# SampleController.php
use JkBennemann\LaravelApiDocumentation\Attributes\AdditionalDocumentation;

//..
#[AdditionalDocumentation(url: 'https://example.com/docs', description: 'External documentation')]
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
            "externalDocs": {
                "url": "https://example.com/docs",
                "description": "External documentation"
            }
            //...
        }
    }
}
```


#### 5. DataResponse
This attribute can be specified multiple data responses for a route.  
In general this is used to provide more detailed information about the response for a given status code.  
*Multiple DataResponse attributes can be added to a route to specify responses for multiple status codes*

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
