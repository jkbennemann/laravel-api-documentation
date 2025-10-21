# Laravel API Documentation - Complete Usage Guide

> **A comprehensive guide for developers to automatically generate accurate API documentation without manual PHP comments**

## Table of Contents

1. [What Is This Package?](#what-is-this-package)
2. [How It Works - The Three Layers](#how-it-works---the-three-layers)
3. [Complete Feature Reference](#complete-feature-reference)
4. [Getting Started](#getting-started)
5. [Common Usage Scenarios](#common-usage-scenarios)
6. [Advanced Features](#advanced-features)
7. [Troubleshooting & Workarounds](#troubleshooting--workarounds)
8. [Best Practices](#best-practices)
9. [Quick Reference](#quick-reference)

---

## What Is This Package?

The Laravel API Documentation package **automatically generates OpenAPI 3.0 documentation** for your Laravel application by analyzing your code. No manual documentation writing required.

### Key Benefits

✅ **Zero Manual Work**: No need to write PHP comments or annotations
✅ **95%+ Accuracy**: Combines static analysis with runtime response capture
✅ **Always Up-to-Date**: Documentation regenerates from actual code
✅ **Multiple API Versions**: Generate separate docs for different APIs (public API, internal API, etc.)
✅ **Interactive UI**: Built-in Swagger, ReDoc, and Scalar viewers

### What Gets Documented Automatically

- **Routes**: All HTTP endpoints (method, path, parameters)
- **Request Parameters**: Query params, path params, request body
- **Request Validation**: Rules from FormRequest classes
- **Response Structures**: Based on JsonResource, Data DTOs, or actual responses
- **Response Status Codes**: 200, 201, 401, 422, 500, etc.
- **Examples**: Real data from test responses
- **Authentication**: Middleware-based auth schemes

---

## How It Works - The Three Layers

The package uses a **three-layer approach** to achieve maximum accuracy:

### Layer 1: Static Analysis (Automatic, Always On)

**What it does:**
- Scans your controllers and analyzes method signatures
- Extracts validation rules from FormRequest classes
- Analyzes JsonResource `toArray()` methods
- Detects Spatie Data DTOs and Laravel responses
- Infers types from method calls and property access

**Accuracy:** ~60-70%

**When it works well:**
- Simple CRUD endpoints
- Standard Laravel resources
- Basic validation rules

**Limitations:**
- Can't detect runtime behavior
- May guess wrong types for complex properties
- Doesn't know actual response data

### Layer 2: Attributes (Manual, High Control)

**What it does:**
- You add PHP attributes to controllers/methods
- Provides explicit type information
- Overrides static analysis

**Accuracy:** ~90-95%

**When to use:**
- Complex response structures
- Custom error responses
- When you want explicit control
- When static analysis gets it wrong

**Available Attributes:**
```php
#[Tag('User Management')]
#[Summary('Create a new user')]
#[Description('Full description here')]
#[DocumentationFile('public-api')]  // Route only in specific doc
#[Parameter('sort', type: 'string', description: 'Sort field')]
#[QueryParameter('page', type: 'integer', required: false)]
#[PathParameter('id', type: 'integer', description: 'User ID')]
#[RequestBody(['name' => 'string', 'email' => 'email'])]
#[DataResponse(status: 200, description: 'Success', resource: UserData::class)]
#[ResponseHeader('X-RateLimit-Remaining', type: 'integer')]
#[IgnoreDataParameter('internal_field')]  // Exclude from docs
```

### Layer 3: Runtime Capture (Automatic, Highest Accuracy)

**What it does:**
- Captures **actual API responses** during your tests
- Stores real schemas in `.schemas/responses/`
- Uses 100% accurate data structures

**Accuracy:** 95-100%

**How it works:**
1. Enable capture: `DOC_CAPTURE_MODE=true`
2. Run your tests: `composer test`
3. Middleware captures all responses automatically
4. Documentation uses captured schemas

**When to use:**
- Always! This is the recommended approach
- Complex nested objects
- Array types that static analysis misses
- When you want maximum accuracy with zero effort

---

## Complete Feature Reference

### Commands

```bash
# Generate documentation
php artisan documentation:generate

# Generate specific version only
php artisan documentation:generate --file=public-api

# Capture responses during tests
php artisan documentation:capture
php artisan documentation:capture --clear  # Clear old captures first
php artisan documentation:capture --stats  # Show statistics

# Validate documentation accuracy
php artisan documentation:validate
php artisan documentation:validate --strict --min-accuracy=95
```

### Configuration Options

#### Basic Setup (`config/api-documentation.php`)

```php
'open_api_version' => '3.0.2',
'version' => '1.0.0',
'title' => 'My API Documentation',

// Exclude routes
'excluded_routes' => [
    'telescope/*',
    'horizon/*',
    '!api/*',  // Exclude everything except API routes
],

// Exclude HTTP methods
'excluded_methods' => ['HEAD', 'OPTIONS'],
```

#### Multi-Version Documentation

```php
'ui' => [
    'default' => 'swagger',
    'swagger' => [
        'enabled' => true,  // Enable Swagger UI
        'route' => '/documentation/swagger',
    ],
    'storage' => [
        'files' => [
            'api' => [
                'name' => 'Internal API',
                'filename' => 'api-documentation.json',
                'process' => true,
            ],
            'public-api' => [
                'name' => 'Public API',
                'filename' => 'public-api-documentation.json',
                'process' => true,
            ],
        ],
    ],
],

'domains' => [
    'api' => [
        'title' => 'Internal API Documentation',
        'main' => 'http://api-gateway.test',  // Domain for filtering
        'servers' => [
            ['url' => 'http://api-gateway.test/api', 'description' => 'Local'],
        ],
    ],
    'public-api' => [
        'title' => 'Public API Documentation',
        'main' => 'http://api.test',
        'servers' => [
            ['url' => 'https://api.example.com/v1', 'description' => 'Production'],
        ],
    ],
],
```

#### Runtime Capture Configuration

```php
'capture' => [
    'enabled' => env('DOC_CAPTURE_MODE', false),
    'storage_path' => base_path('.schemas/responses'),

    'sanitize' => [
        'enabled' => true,
        'sensitive_keys' => ['password', 'token', 'secret'],
        'redacted_value' => '***REDACTED***',
    ],

    'rules' => [
        'include_errors' => true,  // Capture 4xx, 5xx responses
        'max_size' => 1024 * 100,  // Max 100KB
        'exclude_routes' => ['telescope/*'],
    ],
],

'generation' => [
    'use_captured' => true,
    'merge_strategy' => 'captured_priority',  // Captured > Static
    'fallback_to_static' => true,
    'warn_missing_captures' => false,
],
```

#### Smart Features

```php
'smart_responses' => [
    // Relationship type mapping
    'relationship_types' => [
        'hasOne' => ['type' => 'object'],
        'hasMany' => ['type' => 'array', 'items' => ['type' => 'object']],
    ],

    // Method return type detection
    'method_types' => [
        'toIso8601String' => ['type' => 'string', 'format' => 'date-time'],
        'toString' => ['type' => 'string'],
        'toArray' => ['type' => 'array'],
    ],

    // Pagination structure
    'pagination' => [
        'structure' => [
            'data' => true,
            'meta' => true,
            'links' => true,
        ],
    ],
],

'smart_requests' => [
    // Validation rule mapping
    'rule_types' => [
        'string' => ['type' => 'string'],
        'integer' => ['type' => 'integer'],
        'email' => ['type' => 'string', 'format' => 'email'],
        'url' => ['type' => 'string', 'format' => 'uri'],
        'date' => ['type' => 'string', 'format' => 'date'],
        'boolean' => ['type' => 'boolean'],
        'array' => ['type' => 'array'],
        'file' => ['type' => 'string', 'format' => 'binary'],
        'image' => ['type' => 'string', 'format' => 'binary'],
    ],
],
```

---

## Getting Started

### 1. Basic Setup (5 minutes)

```bash
# 1. Package is already installed locally

# 2. Publish config (if not already done)
php artisan vendor:publish --tag=api-documentation-config

# 3. Create storage symlink (for UI access)
php artisan storage:link

# 4. Enable Swagger UI
# Edit config/api-documentation.php:
'swagger' => ['enabled' => true]

# 5. Generate documentation
php artisan documentation:generate

# 6. View documentation
# Visit: http://your-app.test/documentation
```

### 2. Enable Runtime Capture (Recommended)

```bash
# 1. Add to .env
DOC_CAPTURE_MODE=true

# 2. Add middleware to api routes
# app/Http/Kernel.php
'api' => [
    \JkBennemann\LaravelApiDocumentation\Middleware\CaptureApiResponseMiddleware::class,
],

# 3. Run your tests (responses captured automatically)
composer test

# 4. Regenerate documentation (uses captured data)
php artisan documentation:generate

# 5. Check captured files
ls .schemas/responses/
```

### 3. Multi-Version Setup (Optional)

```php
// config/api-documentation.php
'files' => [
    'api' => [...],
    'public-api' => [...],
],
```

```php
// app/Http/Controllers/PublicApi/UserController.php
use JkBennemann\LaravelApiDocumentation\Attributes\DocumentationFile;

#[DocumentationFile('public-api')]
class UserController extends Controller
{
    // This controller's routes appear only in public-api-documentation.json
}
```

---

## Common Usage Scenarios

### Scenario 1: Simple CRUD Endpoint

**Situation:** Basic user list endpoint with pagination

**Solution:** Nothing needed! Static analysis handles it.

```php
class UserController extends Controller
{
    public function index(Request $request)
    {
        return UserResource::collection(
            User::paginate($request->get('per_page', 15))
        );
    }
}
```

**Result:** Automatically documents:
- GET /api/users
- Query param: per_page (integer)
- Response: Paginated collection with data, meta, links

---

### Scenario 2: Complex Response Structure

**Situation:** Response has nested objects and arrays that static analysis gets wrong

**Problem:** `meta` field shows as `string` instead of `array`

**Solution 1 (Best):** Use runtime capture

```bash
DOC_CAPTURE_MODE=true composer test
php artisan documentation:generate
```

**Solution 2:** Add attributes

```php
#[DataResponse(
    status: 200,
    description: 'Success',
    resource: [
        'data' => ['array', 'items' => SubscriptionData::class],
        'meta' => ['array', 'items' => ['type' => 'object']],
    ]
)]
public function index() { ... }
```

**Solution 3:** Use Spatie Data DTO (best for reusability)

```php
class SubscriptionResponseData extends Data
{
    public function __construct(
        /** @var array<SubscriptionData> */
        public array $data,
        public array $meta,
    ) {}
}

#[DataResponse(status: 200, resource: SubscriptionResponseData::class)]
public function index() { ... }
```

---

### Scenario 3: Custom Error Responses

**Situation:** Your API returns custom error formats

**Solution:** Use DataResponse for each status code

```php
#[DataResponse(
    status: 200,
    description: 'Success',
    resource: UserData::class
)]
#[DataResponse(
    status: 404,
    description: 'User not found',
    resource: [
        'error' => ['type' => 'string'],
        'code' => ['type' => 'string'],
    ]
)]
#[DataResponse(
    status: 422,
    description: 'Validation failed',
    resource: [
        'message' => ['type' => 'string'],
        'errors' => ['type' => 'object'],
    ]
)]
public function show(int $id) { ... }
```

---

### Scenario 4: Array Property Types

**Situation:** Properties named `meta`, `items`, `data` showing as string instead of array

**Why:** Static analysis can't always determine array types from code

**Solution 1 (Automatic):** Property name heuristics

The package automatically detects these property names as arrays:
- `meta`
- `items`
- `data`
- `attributes`
- `properties`
- `tags`
- `categories`

**Solution 2:** Runtime capture (most reliable)

```bash
DOC_CAPTURE_MODE=true composer test
```

**Solution 3:** Explicit DTO typing

```php
class SubscriptionData extends Data
{
    public array $meta;  // ← Explicit array type
}
```

**Solution 4:** PHPDoc in JsonResource

```php
class SubscriptionResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var SubscriptionData $subscription */
        $subscription = $this;

        return [
            'meta' => $subscription->meta,  // ← Will detect as array
        ];
    }
}
```

---

### Scenario 5: Excluding Routes from Documentation

**Situation:** Don't want internal/debug routes in public docs

**Solution:**

```php
// config/api-documentation.php
'excluded_routes' => [
    'telescope/*',
    'horizon/*',
    'internal/*',
    '!api/*',  // Include ONLY routes starting with 'api/'
],
```

Or use multiple documentation files:

```php
#[DocumentationFile('internal-api')]  // Not in public docs
class DebugController extends Controller { ... }
```

---

### Scenario 6: Request Validation Documentation

**Situation:** FormRequest validation rules not showing up correctly

**Solution:** The package automatically analyzes validation rules!

```php
class CreateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'age' => ['required', 'integer', 'min:18'],
            'role' => ['required', 'in:admin,user'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
```

**Automatically generates:**
- name: `string`, required, max length 255
- email: `string` with format `email`, required
- age: `integer`, required, minimum 18
- role: `string` with enum `['admin', 'user']`
- avatar: `binary`, optional, max 2MB

**For better descriptions:**

```php
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

class CreateUserRequest extends FormRequest
{
    #[Parameter('name', description: 'User full name')]
    #[Parameter('email', description: 'User email address')]
    #[Parameter('age', description: 'User age in years')]
    public function rules(): array { ... }
}
```

---

### Scenario 7: Shorthand Response Notation

**Situation:** Quick way to define response properties

**Solution:** Use shorthand array notation in DataResponse

```php
#[DataResponse(
    status: 200,
    description: 'Success',
    resource: [
        'access_token' => ['string', null, 'JWT authentication token', 'ey***'],
        'expires_in' => ['integer', false, 'Seconds until expiration', 3600],
        'user' => ['object', false, 'User information', null],
    ]
)]
```

**Format:** `[type, nullable, description, example]`

---

### Scenario 8: Domain-Based Documentation Routing

**Situation:** Different domains show different documentation

**Example:**
- `http://api-gateway.test/documentation` → Shows internal API
- `http://api.test/documentation` → Shows public API

**Setup:**

```php
'domains' => [
    'api' => [
        'main' => 'http://api-gateway.test',  // ← Matches this domain
        // ...
    ],
    'public-api' => [
        'main' => 'http://api.test',  // ← Matches this domain
        // ...
    ],
],
```

The `FileVisibilityTrait` checks `request()->host()` and only shows files matching the current domain.

---

## Advanced Features

### Custom Response Examples

```php
class UserResource extends JsonResource
{
    #[Parameter('name', example: 'John Doe')]
    #[Parameter('email', example: 'john@example.com')]
    #[Parameter('roles', example: ['admin', 'editor'])]
    public function toArray($request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles,
        ];
    }
}
```

### Localized Documentation

```php
'localization' => [
    'default_locale' => 'en',
    'available_locales' => ['en', 'de', 'es'],
    'translations' => [
        'de' => [
            'title' => 'API Dokumentation',
            'responses' => [
                '401' => 'Nicht autorisiert',
            ],
        ],
    ],
],
```

### Custom Type Mapping

```php
'smart_responses' => [
    'method_types' => [
        'toSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$'],
        'toUuid' => ['type' => 'string', 'format' => 'uuid'],
        'toHashId' => ['type' => 'string', 'example' => 'aBc123'],
    ],
],
```

### Captured Response Statistics

```bash
php artisan documentation:capture --stats
```

Output:
```
📊 Capture Statistics:
- Total routes: 45
- Total responses: 78
- By method: GET: 30, POST: 15, PUT: 8, DELETE: 2
- By status: 200: 45, 201: 10, 422: 15, 401: 5, 404: 3
```

---

## Troubleshooting & Workarounds

### Problem: Nested Properties Not Showing

**Symptom:** Response schema shows objects as `{type: "object"}` without properties

**Root Cause:** Static analysis couldn't parse nested structure

**Solution:**

1. **Use runtime capture** (best):
   ```bash
   DOC_CAPTURE_MODE=true composer test
   ```

2. **Add PHPDoc in Resource:**
   ```php
   /** @var SubscriptionData $subscription */
   $subscription = $this;
   ```

3. **Use explicit Spatie Data DTOs:**
   ```php
   class NestedData extends Data
   {
       public string $field1;
       public array $field2;
   }
   ```

---

### Problem: Array Fields Show as String

**Symptom:** `"meta": {"type": "string"}` instead of `{"type": "array"}`

**Root Cause:** Static analysis defaults unknown types to string

**Solutions (in order of preference):**

1. **Runtime capture**: Captures actual array data
2. **Property name heuristic**: Already works for `meta`, `items`, `data`, etc.
3. **Explicit typing in DTO:**
   ```php
   public array $meta;
   ```
4. **DataResponse attribute:**
   ```php
   #[DataResponse(resource: ['meta' => ['type' => 'array']])]
   ```

---

### Problem: Response Examples Are Generic

**Symptom:** Examples show `"string"`, `1`, `true` instead of realistic data

**Root Cause:** Static analysis generates placeholder examples

**Solution:**

1. **Runtime capture** (automatic real examples):
   ```bash
   DOC_CAPTURE_MODE=true composer test
   ```

2. **Manual examples via Parameter attribute:**
   ```php
   #[Parameter('email', example: 'user@example.com')]
   ```

3. **Example in DataResponse:**
   ```php
   #[DataResponse(
       resource: [
           'access_token' => ['string', null, 'JWT token', 'eyJ0eXAiOi...'],
       ]
   )]
   ```

---

### Problem: Swagger UI Not Loading

**Checklist:**

1. ✅ Swagger enabled in config: `'swagger' => ['enabled' => true]`
2. ✅ Storage symlink created: `php artisan storage:link`
3. ✅ Documentation generated: `php artisan documentation:generate`
4. ✅ File exists: `ls storage/app/public/api-documentation.json`
5. ✅ Check browser console for errors
6. ✅ Try clearing cache: `php artisan config:clear`

---

### Problem: Routes Not Appearing in Documentation

**Possible causes:**

1. **Route excluded in config:**
   ```php
   'excluded_routes' => ['api/*']  // ← Check this
   ```

2. **Wrong HTTP method:**
   ```php
   'excluded_methods' => ['GET']  // ← Check this
   ```

3. **No middleware:**
   Routes must have middleware for security scheme detection

4. **Wrong DocumentationFile attribute:**
   ```php
   #[DocumentationFile('wrong-name')]  // Doesn't match config
   ```

---

### Problem: Sensitive Data in Captured Responses

**Symptom:** Passwords/tokens visible in `.schemas/responses/`

**Solution:** Sanitization is automatic, but verify config:

```php
'capture' => [
    'sanitize' => [
        'enabled' => true,
        'sensitive_keys' => [
            'password',
            'token',
            'secret',
            'api_key',
            // Add your custom sensitive fields
        ],
        'redacted_value' => '***REDACTED***',
    ],
],
```

---

## Best Practices

### 1. Always Use Runtime Capture for Production Docs

```bash
# In CI/CD pipeline
DOC_CAPTURE_MODE=true composer test
php artisan documentation:generate
```

**Why:** 95-100% accuracy with zero manual work

---

### 2. Commit Captured Schemas to Git

```bash
git add .schemas/responses/
git commit -m "Update API response schemas"
```

**Why:** Team members get accurate documentation without running tests

---

### 3. Use Spatie Data DTOs for Reusable Schemas

```php
class UserData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?array $meta = [],
    ) {}
}

// Use across multiple endpoints
#[DataResponse(status: 200, resource: UserData::class)]
```

**Why:** Single source of truth, better type safety, automatic schema generation

---

### 4. Document Intent with Attributes

Even with runtime capture, add descriptions:

```php
#[Tag('User Management')]
#[Summary('Create a new user account')]
#[Description('Creates a new user with email verification sent automatically')]
```

**Why:** Provides context that code can't convey

---

### 5. Organize with Multiple Documentation Files

```php
#[DocumentationFile('public-api')]    // External partners
#[DocumentationFile('internal-api')]  // Internal services
#[DocumentationFile('admin-api')]     // Admin panel
```

**Why:** Different audiences need different documentation

---

### 6. Validate Documentation Accuracy

```bash
php artisan documentation:validate --strict --min-accuracy=95
```

**Why:** Catches missing schemas and accuracy issues

---

### 7. Exclude Debugging Routes

```php
'excluded_routes' => [
    'telescope/*',
    'horizon/*',
    '_debugbar/*',
    'testing/*',
],
```

**Why:** Keep docs focused on actual API

---

### 8. Version Your Documentation

```php
'version' => env('API_VERSION', '1.0.0'),
```

```bash
php artisan documentation:generate
cp storage/app/public/api-documentation.json docs/v1.0.0/
```

**Why:** Historical reference for breaking changes

---

## Quick Reference

### Workflow Cheat Sheet

```bash
# Development
1. Write code (controllers, resources, requests)
2. Write tests
3. DOC_CAPTURE_MODE=true composer test
4. php artisan documentation:generate
5. Visit /documentation

# Production
1. Generate docs in CI: php artisan documentation:generate
2. Deploy storage/app/public/*.json
3. Public docs available at /documentation
```

### Attribute Quick Reference

```php
// Controller/Method Level
#[Tag('Category Name')]
#[Summary('Short description')]
#[Description('Long description')]
#[DocumentationFile('api-name')]

// Parameters
#[Parameter('field', type: 'string', description: 'desc', example: 'ex')]
#[QueryParameter('page', type: 'integer', required: false)]
#[PathParameter('id', type: 'string', description: 'Resource ID')]
#[IgnoreDataParameter('internal_field')]

// Responses
#[DataResponse(status: 200, description: 'Success', resource: UserData::class)]
#[ResponseHeader('X-Custom', type: 'string', description: 'Header desc')]

// Request Body
#[RequestBody(['name' => 'string', 'email' => 'email'])]
```

### Config Quick Reference

```php
// Basic
'title' => 'API Name'
'version' => '1.0.0'
'excluded_routes' => ['pattern/*']
'excluded_methods' => ['HEAD', 'OPTIONS']

// UI
'swagger' => ['enabled' => true]
'redoc' => ['enabled' => false]
'scalar' => ['enabled' => false]

// Capture
'capture' => ['enabled' => env('DOC_CAPTURE_MODE')]

// Generation
'generation' => [
    'merge_strategy' => 'captured_priority',
    'use_captured' => true,
]
```

### Command Quick Reference

```bash
php artisan documentation:generate                    # Generate all docs
php artisan documentation:generate --file=public-api  # Generate one
php artisan documentation:capture                     # Run tests with capture
php artisan documentation:validate --min-accuracy=95  # Validate accuracy
php artisan storage:link                              # Create symlink
```

---

## When to Use What

| Scenario | Solution | Accuracy | Effort |
|----------|----------|----------|--------|
| Simple CRUD | Static analysis (automatic) | 70% | None |
| Standard Laravel Resources | Static analysis | 80% | None |
| Complex nested objects | Runtime capture | 95-100% | None (after setup) |
| Custom error formats | Attributes | 95% | Low |
| Specific examples | Attributes or capture | 100% | Low |
| Multiple API versions | DocumentationFile attribute | N/A | Low |
| Maximum accuracy | Runtime capture + DTOs | 100% | Medium |

**General Rule:** Start with runtime capture for all endpoints. Add attributes only when you need custom descriptions or have specific requirements.

---

## Summary

✅ **For most developers:** Enable runtime capture, run tests, done
✅ **For complex cases:** Use Spatie Data DTOs + runtime capture
✅ **For specific control:** Add attributes where needed
✅ **For multi-API projects:** Use DocumentationFile attribute

The package is designed to work with **zero configuration** for standard Laravel applications, and provides **progressive enhancement** for complex scenarios.

---

**Questions?** Check the [CHANGELOG.md](./CHANGELOG.md) for recent updates and the package configuration file for all available options.
