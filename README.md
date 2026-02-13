# Laravel API Documentation

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jkbennemann/laravel-api-documentation.svg?style=flat-square)](https://packagist.org/packages/jkbennemann/laravel-api-documentation)
[![Total Downloads](https://img.shields.io/packagist/dt/jkbennemann/laravel-api-documentation.svg?style=flat-square)](https://packagist.org/packages/jkbennemann/laravel-api-documentation)

A zero-configuration OpenAPI 3.1.0 documentation generator for Laravel. Analyzes your routes, controllers, form requests, and responses using AST parsing, PHP reflection, and optional runtime capture - then outputs a complete, valid OpenAPI spec without you writing a single annotation.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require jkbennemann/laravel-api-documentation
```

Publish the configuration file (optional):

```bash
php artisan vendor:publish --tag="api-documentation-config"
```

Generate your documentation:

```bash
php artisan api:generate
```

That's it. The package discovers your routes, analyzes your code, and writes an OpenAPI 3.1.0 spec to `storage/app/public/api-documentation.json`.

## Table of Contents

- [How It Works](#how-it-works)
- [Quick Start](#quick-start)
- [Commands](#commands)
- [Runtime Capture](#runtime-capture)
- [PHP Attributes](#php-attributes)
- [Configuration](#configuration)
- [Tags & Documentation](#tags--documentation)
- [Documentation Viewers](#documentation-viewers)
- [Output Formats](#output-formats)
- [Plugin System](#plugin-system)
- [Built-in Plugins](#built-in-plugins)
- [Creating a Plugin](#creating-a-plugin)
- [Integrations](#integrations)
- [CI/CD Integration](#cicd-integration)
- [Security](#security)
- [Credits](#credits)
- [License](#license)

## How It Works

The package uses a 5-layer pipeline to generate documentation:

```
1. Route Discovery     Collects routes, applies filters, extracts metadata
        |
2. Analysis Pipeline   Chains priority-ordered analyzers for requests, responses,
        |              query parameters, errors, and security schemes
3. Schema Registry     Deduplicates schemas via content fingerprinting, manages $ref
        |
4. Merge Engine        Combines static analysis + runtime capture + attributes
        |              (configurable priority: static_first or captured_first)
5. OpenAPI Emission    Builds final spec, resolves references, writes output
```

### What Gets Analyzed Automatically

**Requests:**
- `FormRequest` validation rules (types, formats, min/max, enums, patterns, required/optional)
- Inline `$this->validate()` and `Validator::make()` calls
- `$request->get()`, `$request->query()`, `$request->integer()` method calls in controller bodies
- File upload detection (`file`, `image` rules) with automatic `multipart/form-data` content type
- Nested parameter structures (`user.profile.name`)

**Responses:**
- Return type declarations (`JsonResource`, `JsonResponse`, `ResourceCollection`, Spatie `Data`)
- Controller method body analysis (traces `response()->json([...])` return statements)
- PHPDoc `@return` types including union types (`UserData|AdminData` produces `oneOf`)
- Abort statements (`abort(404)`, `abort_if()`) for error responses
- Paginated responses with `data`, `meta`, and `links` structure
- `JsonResource::toArray()` analysis (property types, `$this->when()`, `$this->whenLoaded()`, `$this->merge()`)

**Query Parameters:**
- `FormRequest` rules on GET routes become query parameters
- PHPDoc `@queryParam` annotations
- Pagination detection (`paginate()`, `simplePaginate()`, `cursorPaginate()`) adds `page`/`per_page` params

**Errors:**
- `FormRequest` presence adds `422` validation error
- `auth`/`sanctum` middleware adds `401` unauthorized
- `Gate`/`authorize` calls add `403` forbidden
- Route model binding adds `404` not found
- `throttle` middleware adds `429` rate limited
- Custom exception handler analysis for app-specific error schemas

**Security:**
- `auth:sanctum`, `auth:api`, `jwt.auth` middleware detected as Bearer token auth
- OAuth scopes extracted from `scope:` and `scopes:` middleware
- Sanctum abilities extracted from `ability:` and `abilities:` middleware

## Quick Start

### Zero-Config (Static Analysis)

For a standard Laravel API, no configuration is needed:

```bash
php artisan api:generate
```

The package reads your routes, controllers, form requests, and resources to produce a spec. This typically achieves ~70% schema accuracy, depending on how explicitly typed your code is.

### With Runtime Capture (Recommended)

Enable runtime capture to use real API responses from your test suite, bringing accuracy to 95%+:

**1. Add to `phpunit.xml`:**

```xml
<php>
    <env name="DOC_CAPTURE_MODE" value="true"/>
</php>
```

**2. Register the middleware in your test setup** (e.g., `TestCase.php` or a service provider used during testing):

```php
use JkBennemann\LaravelApiDocumentation\Middleware\CaptureApiResponseMiddleware;

// In a test service provider or TestCase::setUp()
$this->app['router']->pushMiddlewareToGroup('api', CaptureApiResponseMiddleware::class);
```

**3. Run your tests, then generate:**

```bash
php artisan test
php artisan api:generate
```

The middleware captures request/response data to `.schemas/responses/` during test runs. The generator merges this with static analysis to produce accurate schemas with real examples. See [Runtime Capture](#runtime-capture) for details.

### With Attributes (Precision)

For routes where auto-detection falls short (proxy controllers, dynamic responses), add PHP 8 attributes:

```php
use JkBennemann\LaravelApiDocumentation\Attributes\{Tag, Summary, DataResponse};

#[Tag('Users')]
#[Summary('List all users')]
#[DataResponse(200, description: 'Paginated user list', resource: UserResource::class)]
public function index(): ResourceCollection
{
    return UserResource::collection(User::paginate());
}
```

## Commands

### `api:generate` - Generate Documentation

```bash
php artisan api:generate [options]
```

| Option | Description |
|---|---|
| `--format=json` | Output format: `json`, `yaml`, or `postman` |
| `--domain=` | Generate for a specific domain only |
| `--route=` | Generate for a single route URI (for debugging) |
| `--method=GET` | HTTP method when using `--route` |
| `--dev` | Include development servers in output |
| `--clear-cache` | Clear AST cache before generating |
| `--verbose-analysis` | Show analyzer decisions during generation |
| `--watch` | Watch for file changes and regenerate automatically |

**Examples:**

```bash
# Standard generation
php artisan api:generate

# YAML output
php artisan api:generate --format=yaml

# Debug a single route
php artisan api:generate --route=api/users --method=GET --verbose-analysis

# Watch mode during development
php artisan api:generate --watch

# Export as Postman collection
php artisan api:generate --format=postman
```

### `api:lint` - Lint Spec Quality

```bash
php artisan api:lint [options]
```

| Option | Description |
|---|---|
| `--file=` | Path to an existing OpenAPI JSON file to lint |
| `--domain=` | Generate and lint a specific domain |
| `--json` | Output results as JSON |

Reports coverage (summaries, descriptions, examples, error responses, request/response bodies), issues (errors, warnings), and a quality score (0-100) with a letter grade.

```bash
php artisan api:lint
# Score: 82/100 (B)
# Coverage: summaries 95%, descriptions 60%, examples 80%
```

### `api:diff` - Detect Breaking Changes

```bash
php artisan api:diff <old-spec> <new-spec> [options]
```

| Option | Description |
|---|---|
| `--fail-on-breaking` | Exit with code 1 if breaking changes are found |
| `--json` | Output results as JSON |

Compares two OpenAPI specs and reports breaking vs non-breaking changes. Detects removed endpoints, removed response fields, type changes, new required parameters, and added auth requirements.

```bash
# Compare specs
php artisan api:diff public/api-v1.json public/api-v2.json

# Use in CI to block breaking changes
php artisan api:diff old.json new.json --fail-on-breaking
```

### `api:types` - Generate TypeScript Definitions

```bash
php artisan api:types [options]
```

| Option | Description |
|---|---|
| `--output=` | Output file path (default: `resources/js/types/api.d.ts`) |
| `--file=` | Path to an existing OpenAPI JSON file |
| `--stdout` | Print to stdout instead of writing to file |

Generates TypeScript interfaces from your OpenAPI component schemas, including request/response types for operations that have an `operationId`.

### `api:clear-cache` - Clear Caches

```bash
php artisan api:clear-cache [options]
```

| Option | Description |
|---|---|
| `--ast` | Clear AST analysis cache only |
| `--captures` | Clear captured responses only |

Without flags, clears both AST and capture caches.

### `api:plugins` - List Registered Plugins

```bash
php artisan api:plugins
```

Shows all registered plugins with their names, priorities, and capabilities (which interfaces they implement).

## Runtime Capture

Runtime capture records real HTTP request/response data during your test runs. This data is then merged with static analysis during generation.

### How It Works

1. The `CaptureApiResponseMiddleware` intercepts API responses in `local`/`testing` environments (never in production)
2. For each response, it infers an OpenAPI schema from the JSON structure and stores it to `.schemas/responses/`
3. Captures are **idempotent** - if the schema structure hasn't changed between test runs, the file is not rewritten (no noisy git diffs)
4. Sensitive data (passwords, tokens, API keys, credit card numbers) is automatically redacted
5. During `api:generate`, the captured schemas are merged with static analysis results

### Configuration

In `config/api-documentation.php`:

```php
'capture' => [
    'enabled' => env('DOC_CAPTURE_MODE', false),
    'storage_path' => base_path('.schemas/responses'),
    'capture' => [
        'requests'  => true,   // Capture request bodies and query params
        'responses' => true,   // Capture response bodies
        'headers'   => true,   // Capture relevant headers
        'examples'  => true,   // Store sanitized examples
    ],
    'sanitize' => [
        'enabled' => true,
        'sensitive_keys' => [
            'password', 'token', 'secret', 'api_key', 'access_token',
            'credit_card', 'cvv', 'ssn', // ... and more
        ],
        'redacted_value' => '***REDACTED***',
    ],
    'rules' => [
        'max_size' => 1024 * 100,  // Skip responses over 100KB
        'exclude_routes' => ['telescope/*', 'horizon/*', '_debugbar/*', 'sanctum/*'],
    ],
],
```

### Merge Priority

Control whether static analysis or captured data takes precedence:

```php
'analysis' => [
    'priority' => 'static_first',  // or 'captured_first'
],
```

- **`static_first`** (default): Attributes > Static Analysis > Runtime Capture
- **`captured_first`**: Attributes > Runtime Capture > Static Analysis

### Version Control

Consider committing `.schemas/responses/` to version control. Captures are idempotent, so unchanged schemas produce no git diffs. This gives you:
- Documentation that works without running the full test suite
- A record of your API's response shapes over time
- Faster CI builds (skip test run, generate from committed captures)

## PHP Attributes

Attributes give you precise control when auto-detection needs help. They always take the highest priority.

### `#[Tag]`

Groups operations in the generated documentation. Applied at class or method level. Optionally includes a description (supports Markdown) that appears in ReDoc/Scalar as introductory content for the tag section.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;

#[Tag('Users')]
class UserController extends Controller {}

// With description
#[Tag('Widgets', description: 'Operations for managing widgets and their configurations.')]
public function index() {}

// Multiple tags
#[Tag(['Users', 'Admin'])]
public function promoteUser() {}
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `value` | `string\|array\|null` | `null` | Tag name or array of tag names |
| `description` | `string\|null` | `null` | Tag description (Markdown supported). Can also be set via config. |

> **Precedence:** Config `tags` descriptions override `#[Tag]` attribute descriptions. See [Tags & Documentation](#tags--documentation).

### `#[Summary]` and `#[Description]`

Set the operation summary (short) and description (detailed). Also inferred from PHPDoc if not provided.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;

#[Summary('List all users')]
#[Description('Returns a paginated list of users with optional filtering by status and role.')]
public function index() {}
```

### `#[DataResponse]`

Define response schemas explicitly. Repeatable for multiple status codes.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;

#[DataResponse(200, description: 'User details', resource: UserResource::class)]
#[DataResponse(404, description: 'User not found', resource: ['message' => 'string'])]
public function show(string $id) {}
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `status` | `int` | *(required)* | HTTP status code |
| `description` | `string` | `''` | Response description |
| `resource` | `string\|array\|null` | `[]` | Resource class, Spatie Data class, or inline schema |
| `headers` | `array` | `[]` | Response headers (`['X-Token' => 'description']`) |
| `isCollection` | `bool` | `false` | Whether the response is a collection |

### `#[Parameter]`

Enhance request body or response properties. Applied at class level (resources) or method level (form requests). Repeatable.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;

// On a FormRequest's rules() method
#[Parameter(name: 'email', required: true, format: 'email', description: 'User email', example: 'john@example.com')]
#[Parameter(name: 'role', type: 'string', description: 'User role', deprecated: true)]
public function rules(): array { /* ... */ }

// On a JsonResource's toArray() method
#[Parameter(name: 'id', type: 'string', format: 'uuid', description: 'Unique identifier')]
#[Parameter(name: 'avatar_url', type: 'string', format: 'uri', description: 'Profile image URL')]
public function toArray($request): array { /* ... */ }
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `name` | `string` | *(required)* | Property name |
| `required` | `bool` | `false` | Whether the property is required |
| `type` | `string` | `'string'` | OpenAPI type (also accepts aliases: `date`, `email`, `uuid`, etc.) |
| `format` | `string\|null` | `null` | OpenAPI format |
| `description` | `string` | `''` | Property description |
| `deprecated` | `bool` | `false` | Mark as deprecated |
| `example` | `mixed` | `null` | Example value |
| `nullable` | `bool` | `false` | Allow null values |
| `minLength` | `int\|null` | `null` | Minimum string length |
| `maxLength` | `int\|null` | `null` | Maximum string length |
| `items` | `string\|null` | `null` | Array item type |
| `resource` | `string\|null` | `null` | Nested resource class |

**Type aliases** are automatically normalized to valid OpenAPI types:

| Alias | Becomes |
|---|---|
| `date` | `type: "string", format: "date"` |
| `datetime`, `date-time`, `timestamp` | `type: "string", format: "date-time"` |
| `time` | `type: "string", format: "time"` |
| `email` | `type: "string", format: "email"` |
| `url`, `uri` | `type: "string", format: "uri"` |
| `uuid` | `type: "string", format: "uuid"` |
| `ip`, `ipv4` | `type: "string", format: "ipv4"` |
| `ipv6` | `type: "string", format: "ipv6"` |
| `binary` | `type: "string", format: "binary"` |
| `byte` | `type: "string", format: "byte"` |
| `password` | `type: "string", format: "password"` |
| `int` | `type: "integer"` |
| `bool` | `type: "boolean"` |
| `float`, `double` | `type: "number"` |

### `#[PathParameter]`

Document path parameters. Repeatable.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\PathParameter;

#[PathParameter(name: 'id', type: 'string', format: 'uuid', description: 'User ID', example: '550e8400-e29b-41d4-a716-446655440000')]
public function show(string $id) {}
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `name` | `string` | *(required)* | Parameter name (must match route parameter) |
| `type` | `string` | `'string'` | Parameter type |
| `format` | `string\|null` | `null` | Parameter format |
| `description` | `string` | `''` | Parameter description |
| `required` | `bool` | `true` | Whether required |
| `example` | `mixed` | `null` | Example value |

### `#[QueryParameter]`

Document query parameters explicitly. Repeatable.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\QueryParameter;

#[QueryParameter(name: 'status', description: 'Filter by status', enum: ['active', 'inactive', 'banned'])]
#[QueryParameter(name: 'per_page', type: 'integer', description: 'Items per page', example: 25)]
public function index() {}
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `name` | `string` | *(required)* | Parameter name |
| `type` | `string` | `'string'` | Parameter type |
| `format` | `string\|null` | `null` | Parameter format |
| `description` | `string` | `''` | Parameter description |
| `required` | `bool` | `false` | Whether required |
| `example` | `mixed` | `null` | Example value |
| `enum` | `array\|null` | `null` | Allowed values |

### `#[ResponseHeader]`

Document response headers. Repeatable.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\ResponseHeader;

#[ResponseHeader(name: 'X-Request-Id', description: 'Unique request identifier', format: 'uuid')]
#[ResponseHeader(name: 'X-RateLimit-Remaining', type: 'integer', description: 'Remaining requests')]
public function index() {}
```

### `#[RequestBody]` and `#[ResponseBody]`

Low-level control over request/response schemas when you need full override.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\RequestBody;
use JkBennemann\LaravelApiDocumentation\Attributes\ResponseBody;

#[RequestBody(description: 'Webhook payload', contentType: 'application/json', dataClass: WebhookPayload::class)]
#[ResponseBody(statusCode: 202, description: 'Accepted')]
public function handleWebhook() {}
```

### `#[ExcludeFromDocs]`

Exclude a controller or specific method from documentation.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\ExcludeFromDocs;

// Exclude entire controller
#[ExcludeFromDocs]
class InternalController extends Controller {}

// Exclude single method
class UserController extends Controller
{
    #[ExcludeFromDocs]
    public function debug() {}
}
```

### `#[AdditionalDocumentation]`

Link to external documentation.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\AdditionalDocumentation;

#[AdditionalDocumentation(url: 'https://docs.example.com/auth', description: 'Authentication guide')]
public function login() {}
```

### `#[DocumentationFile]`

Route an endpoint to a specific documentation file (for multi-file setups).

```php
use JkBennemann\LaravelApiDocumentation\Attributes\DocumentationFile;

#[DocumentationFile('internal-api')]
class InternalApiController extends Controller {}
```

### `#[IgnoreDataParameter]`

Exclude a Spatie Data property from the request body schema.

```php
use JkBennemann\LaravelApiDocumentation\Attributes\IgnoreDataParameter;

#[IgnoreDataParameter(parameters: 'internal_field')]
public function store(CreateUserData $data) {}
```

### PHPDoc Annotations

The package also reads PHPDoc blocks:

```php
/**
 * Get a list of users
 *
 * Returns all active users with optional filtering.
 *
 * @queryParam per_page integer Number of results per page. Example: 25
 * @queryParam search string Search by name or email. Example: john
 * @queryParam status string Filter by account status. Example: active
 *
 * @return \Illuminate\Http\Resources\Json\ResourceCollection<UserResource>
 *
 * @deprecated Use /api/v2/users instead
 */
public function index(Request $request): ResourceCollection {}
```

- The first line becomes the `summary`, the rest becomes the `description`
- `@queryParam` entries are extracted as query parameters
- `@return` type is used for response schema detection
- `@deprecated` marks the operation as deprecated

## Configuration

After publishing the config (`php artisan vendor:publish --tag="api-documentation-config"`), the file is at `config/api-documentation.php`. Key sections:

### OpenAPI Metadata

```php
'open_api_version' => '3.1.0',
'version' => '1.0.0',
'title' => 'My API',  // Defaults to APP_NAME
```

### Route Filtering

```php
'include_vendor_routes' => false,
'include_closure_routes' => false,
'excluded_routes' => [
    'telescope/*',
    'horizon/*',
],
'excluded_methods' => ['HEAD', 'OPTIONS'],
```

### Servers

```php
'servers' => [
    ['url' => env('APP_URL', 'http://localhost'), 'description' => 'Local'],
    ['url' => 'https://api.example.com', 'description' => 'Production'],
],
```

### Analysis

```php
'analysis' => [
    'priority' => 'static_first',  // 'static_first' or 'captured_first'
    'cache_ttl' => 3600,           // AST cache TTL in seconds (0 disables)
    'cache_path' => null,          // Defaults to storage_path()
],
```

### Code Samples

```php
'code_samples' => [
    'enabled' => false,
    'languages' => ['bash', 'javascript', 'php', 'python'],
    'base_url' => null,  // null uses '{baseUrl}' placeholder
],
```

When enabled, adds `x-codeSamples` to each operation with working cURL, fetch, Guzzle, and requests examples.

### Error Responses

```php
'error_responses' => [
    'enabled' => true,
    'defaults' => [
        'status_messages' => [
            '400' => 'The request could not be processed.',
            '401' => 'Authentication credentials are required.',
            '403' => 'You do not have permission.',
            '404' => 'The requested resource was not found.',
            '422' => 'The request contains invalid data.',
            '429' => 'Too many requests.',
            '500' => 'An internal server error occurred.',
        ],
    ],
],
```

### Validation Rule Mappings

```php
'smart_requests' => [
    'rule_types' => [
        'string'   => ['type' => 'string'],
        'integer'  => ['type' => 'integer'],
        'boolean'  => ['type' => 'boolean'],
        'email'    => ['type' => 'string', 'format' => 'email'],
        'uuid'     => ['type' => 'string', 'format' => 'uuid'],
        'url'      => ['type' => 'string', 'format' => 'uri'],
        'file'     => ['type' => 'string', 'format' => 'binary'],
        'image'    => ['type' => 'string', 'format' => 'binary'],
        // ... and more
    ],
],
```

## Tags & Documentation

Enrich your documentation viewers (ReDoc, Scalar) with tag descriptions, navigation groups, documentation-only pages, and external links. All settings are zero-config by default — empty arrays and `null` values produce no output.

### Tag Descriptions

Add Markdown descriptions to tags. These appear as introductory content in tag sections.

```php
// config/api-documentation.php
'tags' => [
    'Users' => 'Operations for managing user accounts.',
    'Billing' => '## Billing API\nAll endpoints require an active subscription.',
],
```

Descriptions can also be set via the `#[Tag]` attribute:

```php
#[Tag('Users', description: 'Operations for managing user accounts.')]
```

Config descriptions take precedence over attribute descriptions.

### Tag Groups (`x-tagGroups`)

Group tags into sections for the ReDoc/Scalar navigation sidebar:

```php
'tag_groups' => [
    ['name' => 'User Management', 'tags' => ['Users', 'Roles', 'Permissions']],
    ['name' => 'Commerce', 'tags' => ['Products', 'Orders', 'Billing']],
],
```

This emits the `x-tagGroups` vendor extension recognized by ReDoc and Scalar. By default, any tags not assigned to a group are automatically collected into an "Other" group so they remain visible in the sidebar. To instead hide ungrouped tags (the default ReDoc/Scalar behavior), set:

```php
'tag_groups_include_ungrouped' => false,
```

### Trait Tags (`x-traitTag`)

Add documentation-only tags that appear in the sidebar but aren't associated with any operations. Useful for guides, introductions, or changelogs:

```php
'trait_tags' => [
    [
        'name' => 'Getting Started',
        'description' => "# Welcome\n\nThis API uses Bearer token authentication. See the [Authentication](#section/Authentication) section for details.",
    ],
    [
        'name' => 'Changelog',
        'description' => "# Changelog\n\n## v2.0\n- New billing endpoints\n- Improved error responses",
    ],
],
```

Trait tags are emitted with `x-traitTag: true` and support full Markdown in the description.

### External Documentation

Add a spec-level link to external documentation:

```php
'external_docs' => [
    'url' => 'https://docs.example.com',
    'description' => 'Full developer documentation',
],
```

Set to `null` (default) to omit from the spec.

### Domain Overrides

All four settings (`tags`, `tag_groups`, `trait_tags`, `external_docs`) can be overridden per domain:

```php
'domains' => [
    'public' => [
        'title' => 'Public API',
        'tags' => ['Users' => 'Public user endpoints.'],
        'tag_groups' => [
            ['name' => 'Core', 'tags' => ['Users', 'Products']],
        ],
        'external_docs' => ['url' => 'https://docs.example.com'],
    ],
    'internal' => [
        'title' => 'Internal API',
        'tags' => ['Admin' => 'Internal admin operations.'],
    ],
],
```

### Multi-Domain Support

Generate separate specs for different API domains:

```php
'domains' => [
    'public' => [
        'title' => 'Public API',
        'main' => 'https://api.example.com',
        'servers' => [
            ['url' => 'https://api.example.com', 'description' => 'Production'],
        ],
    ],
    'internal' => [
        'title' => 'Internal API',
        'main' => 'https://internal.example.com',
        'servers' => [
            ['url' => 'https://internal.example.com', 'description' => 'Internal'],
        ],
    ],
],
```

```bash
php artisan api:generate --domain=public
```

### Multi-File Support

Output multiple documentation files from a single app:

```php
'ui' => [
    'storage' => [
        'files' => [
            'default' => [
                'name' => 'Public API',
                'filename' => 'api-documentation.json',
                'process' => true,
            ],
            'internal' => [
                'name' => 'Internal API',
                'filename' => 'internal-api.json',
                'process' => true,
            ],
        ],
    ],
],
```

Use `#[DocumentationFile('internal')]` on controllers to route them to a specific file.

## Documentation Viewers

Three built-in documentation UIs are available. Enable them in your config:

```php
'ui' => [
    'default' => 'swagger',  // 'swagger', 'redoc', or 'scalar'

    'swagger' => [
        'enabled' => true,
        'route' => '/documentation/swagger',
        'version' => '5.17.14',
        'middleware' => ['web'],
    ],

    'redoc' => [
        'enabled' => true,
        'route' => '/documentation/redoc',
        'version' => '2.2.0',
        'middleware' => ['web'],
    ],

    'scalar' => [
        'enabled' => true,
        'route' => '/documentation/scalar',
        'version' => '2.2.0',
        'middleware' => ['web'],
    ],
],
```

When any UI is enabled, a default hub page is registered at `/documentation` that redirects to your chosen default viewer.

To publish and customize the view templates:

```bash
php artisan vendor:publish --tag="api-documentation-views"
```

## Output Formats

### JSON (default)

```bash
php artisan api:generate
# Output: storage/app/public/api-documentation.json
```

### YAML

Requires the `ext-yaml` PHP extension, or falls back to a built-in converter.

```bash
php artisan api:generate --format=yaml
```

### Postman Collection

Exports a Postman Collection v2.1 file, grouped by tags, with auth headers, path/query parameters, and request body examples.

```bash
php artisan api:generate --format=postman
```

### TypeScript Definitions

```bash
php artisan api:types
# Output: resources/js/types/api.d.ts
```

## Plugin System

The package is built around 6 plugin interfaces. Each interface represents a specific analysis capability. All built-in analyzers use the same interfaces, so plugins have the same power as core functionality.

### Plugin Interfaces

| Interface | Purpose | Method Signature |
|---|---|---|
| `RequestBodyExtractor` | Extract request body schemas | `extract(AnalysisContext $ctx): ?SchemaResult` |
| `ResponseExtractor` | Extract response schemas | `extract(AnalysisContext $ctx): array` (of `ResponseResult`) |
| `QueryParameterExtractor` | Extract query parameters | `extract(AnalysisContext $ctx): array` (of `ParameterResult`) |
| `SecuritySchemeDetector` | Detect auth schemes | `detect(AnalysisContext $ctx): ?array` |
| `OperationTransformer` | Post-process operations | `transform(array $operation, AnalysisContext $ctx): array` |
| `ExceptionSchemaProvider` | Custom exception schemas | `provides(string $exceptionClass): bool` + `getResponse(string $exceptionClass): ResponseResult` |

Every plugin must implement the base `Plugin` interface:

```php
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;

interface Plugin
{
    public function name(): string;
    public function boot(PluginRegistry $registry): void;
    public function priority(): int;
}
```

### Priority System

Analyzers run in priority order (highest first). The first non-null result wins for request bodies; all results are collected for responses and query parameters.

| Range | Used By |
|---|---|
| 100 | Attribute-based analyzers (manual overrides) |
| 80-90 | Core static analyzers (FormRequest, ReturnType) |
| 60-70 | Runtime capture analyzers |
| 40-50 | Built-in plugins (BearerAuth, Pagination, Spatie) |
| 1-39 | Community plugins (recommended range) |

### Registering Plugins

**Via config:**

```php
// config/api-documentation.php
'plugins' => [
    App\Documentation\MyPlugin::class,
],
```

**Via Composer auto-discovery** (for package authors):

```json
{
    "extra": {
        "api-documentation": {
            "plugins": [
                "Vendor\\Package\\MyPlugin"
            ]
        }
    }
}
```

**Programmatically at runtime:**

```php
use JkBennemann\LaravelApiDocumentation\LaravelApiDocumentation;

LaravelApiDocumentation::extend(new MyPlugin());
```

## Built-in Plugins

### BearerAuthPlugin

Always active. Detects Bearer token authentication from `auth:sanctum`, `auth:api`, and `jwt.auth` middleware. Extracts OAuth scopes and Sanctum abilities.

### PaginationPlugin

Always active. Detects `paginate()`, `simplePaginate()`, and `cursorPaginate()` calls via AST analysis. Wraps response schemas with `data`/`meta`/`links` pagination envelope.

### CodeSamplePlugin

Enabled when `code_samples.enabled` is `true` in config. Generates working code examples in bash (cURL), JavaScript (fetch), PHP (Guzzle), and Python (requests) with proper auth headers and request bodies.

### SpatieDataPlugin

Auto-detected when `spatie/laravel-data` is installed. Extracts request body schemas from Spatie Data DTO constructor properties. Handles optional properties, `Lazy` types, and nested DTOs with `$ref` deduplication.

### SpatieQueryBuilderPlugin

Auto-detected when `spatie/laravel-query-builder` is installed. Extracts `filter[...]`, `sort`, `include`, and `fields` query parameters from `allowedFilters()`, `allowedSorts()`, `allowedIncludes()`, and `allowedFields()` calls.

### JsonApiPlugin

Auto-detected when `timacdonald/json-api` is installed. Generates proper JSON:API response schemas (`data`/`attributes`/`relationships`/`links`), sets content type to `application/vnd.api+json`, and adds the `Accept` header.

### LaravelActionsPlugin

Auto-detected when `lorisleiva/laravel-actions` is installed. Extracts request schemas from Action classes using the `AsController` trait by analyzing `rules()` methods and `handle()` parameters.

## Creating a Plugin

Here is a complete example of a plugin that adds a custom header to every operation:

```php
<?php

namespace App\Documentation;

use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class CorrelationIdPlugin implements Plugin, OperationTransformer
{
    public function name(): string
    {
        return 'correlation-id';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addOperationTransformer($this, priority: 30);
    }

    public function priority(): int
    {
        return 30;
    }

    public function transform(array $operation, AnalysisContext $ctx): array
    {
        $operation['parameters'][] = [
            'name' => 'X-Correlation-ID',
            'in' => 'header',
            'required' => false,
            'description' => 'Optional correlation ID for request tracing',
            'schema' => ['type' => 'string', 'format' => 'uuid'],
        ];

        return $operation;
    }
}
```

Register it:

```php
// config/api-documentation.php
'plugins' => [
    App\Documentation\CorrelationIdPlugin::class,
],
```

### Plugin with Multiple Capabilities

A plugin can implement multiple interfaces:

```php
class MyPlugin implements Plugin, ResponseExtractor, QueryParameterExtractor
{
    public function name(): string { return 'my-plugin'; }
    public function priority(): int { return 35; }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addResponseExtractor($this, priority: 35);
        $registry->addQueryExtractor($this, priority: 35);
    }

    public function extract(AnalysisContext $ctx): array
    {
        // This method serves both interfaces - differentiate
        // by checking what's being requested via the context
        return [];
    }
}
```

### The AnalysisContext Object

Every analyzer receives an `AnalysisContext` that provides:

- `$ctx->routeInfo` - Route metadata (URI, methods, middleware, parameters)
- `$ctx->reflectionMethod` - PHP `ReflectionMethod` of the controller action
- `$ctx->reflectionClass` - PHP `ReflectionClass` of the controller
- `$ctx->ast` - Parsed AST statements of the controller method body
- `$ctx->classAttributes` - PHP 8 attributes on the controller class
- `$ctx->methodAttributes` - PHP 8 attributes on the method

### Plugin Safety

Plugins that throw during `boot()` are automatically unregistered and logged. A failing plugin never breaks documentation generation for other routes.

### Community Plugin Ideas

Below are detailed implementation examples for plugins the community could build. Each example is a complete, working plugin that can be copied into your project and customized.

#### API Key Authentication

Detects custom middleware that validates API keys via headers or query parameters.

```php
<?php

namespace App\Documentation;

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\SecuritySchemeDetector;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class ApiKeyAuthPlugin implements Plugin, SecuritySchemeDetector
{
    /**
     * Map your middleware aliases to their API key configuration.
     * Adjust these to match your application's middleware names.
     */
    private const MIDDLEWARE_MAP = [
        'api-key'      => ['header', 'X-API-Key'],
        'api_key'      => ['header', 'X-API-Key'],
        'api.key'      => ['header', 'X-API-Key'],
        'client-token' => ['header', 'X-Client-Token'],
    ];

    public function name(): string
    {
        return 'api-key-auth';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addSecurityDetector($this, 45);
    }

    public function priority(): int
    {
        return 45;
    }

    public function detect(AnalysisContext $ctx): ?array
    {
        foreach ($ctx->route->middleware as $middleware) {
            $name = explode(':', $middleware)[0];

            if (isset(self::MIDDLEWARE_MAP[$name])) {
                [$in, $paramName] = self::MIDDLEWARE_MAP[$name];

                return [
                    'name' => 'apiKeyAuth',
                    'scheme' => [
                        'type' => 'apiKey',
                        'in' => $in,          // 'header' or 'query'
                        'name' => $paramName,  // The header/query parameter name
                    ],
                ];
            }
        }

        return null;
    }
}
```

#### RFC 7807 Problem Details

Maps exceptions to standardized [Problem Details](https://www.rfc-editor.org/rfc/rfc7807) responses. This is the only interface (`ExceptionSchemaProvider`) with no built-in implementation — a great opportunity for a community package.

```php
<?php

namespace App\Documentation;

use JkBennemann\LaravelApiDocumentation\Contracts\ExceptionSchemaProvider;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class ProblemDetailsPlugin implements Plugin, ExceptionSchemaProvider
{
    /**
     * Map exception classes to their Problem Details type URI and title.
     * Customize this for your application's exception hierarchy.
     */
    private const EXCEPTION_MAP = [
        \Illuminate\Auth\AuthenticationException::class => [
            'status' => 401,
            'type' => 'https://httpstatuses.com/401',
            'title' => 'Unauthenticated',
        ],
        \Illuminate\Auth\Access\AuthorizationException::class => [
            'status' => 403,
            'type' => 'https://httpstatuses.com/403',
            'title' => 'Forbidden',
        ],
        \Illuminate\Database\Eloquent\ModelNotFoundException::class => [
            'status' => 404,
            'type' => 'https://httpstatuses.com/404',
            'title' => 'Resource Not Found',
        ],
        \Illuminate\Validation\ValidationException::class => [
            'status' => 422,
            'type' => 'https://httpstatuses.com/422',
            'title' => 'Validation Failed',
        ],
    ];

    public function name(): string
    {
        return 'problem-details';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addExceptionSchemaProvider($this);
    }

    public function priority(): int
    {
        return 35;
    }

    public function provides(string $exceptionClass): bool
    {
        return isset(self::EXCEPTION_MAP[$exceptionClass]);
    }

    public function getResponse(string $exceptionClass): ResponseResult
    {
        $config = self::EXCEPTION_MAP[$exceptionClass];

        $properties = [
            'type' => new SchemaObject(type: 'string', format: 'uri', example: $config['type']),
            'title' => new SchemaObject(type: 'string', example: $config['title']),
            'status' => new SchemaObject(type: 'integer', example: $config['status']),
            'detail' => SchemaObject::string(),
            'instance' => new SchemaObject(type: 'string', format: 'uri'),
        ];

        // Validation errors include an additional 'errors' object
        if ($exceptionClass === \Illuminate\Validation\ValidationException::class) {
            $properties['errors'] = SchemaObject::object();
        }

        return new ResponseResult(
            statusCode: $config['status'],
            schema: SchemaObject::object($properties, ['type', 'title', 'status']),
            description: $config['title'],
            contentType: 'application/problem+json',
            source: 'plugin:problem-details',
        );
    }
}
```

#### League Fractal Transformers

Detects [Fractal](https://fractal.thephpleague.com/) transformers and extracts response schemas by analyzing the `transform()` method's return array via AST parsing.

```php
<?php

namespace App\Documentation;

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

class FractalPlugin implements Plugin, ResponseExtractor
{
    public function name(): string
    {
        return 'league-fractal';
    }

    public function boot(PluginRegistry $registry): void
    {
        // Only activate when Fractal is installed
        if (! class_exists(\League\Fractal\TransformerAbstract::class)) {
            return;
        }

        $registry->addResponseExtractor($this, 35);
    }

    public function priority(): int
    {
        return 35;
    }

    public function extract(AnalysisContext $ctx): array
    {
        if (! $ctx->hasAst() || ! $ctx->hasReflection()) {
            return [];
        }

        // Find Fractal transformer usage in the controller method's AST
        $transformerClass = $this->detectTransformer($ctx);
        if ($transformerClass === null) {
            return [];
        }

        // Parse the transformer's transform() method to extract the schema
        $schema = $this->analyzeTransformer($transformerClass);
        if ($schema === null) {
            return [];
        }

        return [
            new ResponseResult(
                statusCode: 200,
                schema: $schema,
                description: 'Success',
                source: 'plugin:fractal',
            ),
        ];
    }

    /**
     * Look for patterns like:
     *   $fractal->item($model, new UserTransformer)
     *   $fractal->collection($models, new UserTransformer)
     *   return fractal($model, new UserTransformer)->toArray()
     */
    private function detectTransformer(AnalysisContext $ctx): ?string
    {
        $nodeFinder = new NodeFinder;
        $news = $nodeFinder->findInstanceOf(
            $ctx->astNode->stmts ?? [],
            Node\Expr\New_::class,
        );

        foreach ($news as $new) {
            if (! $new->class instanceof Node\Name) {
                continue;
            }

            $className = $new->class->toString();
            $resolved = $this->resolveClassName($className, $ctx);

            if ($resolved !== null
                && class_exists($resolved)
                && is_subclass_of($resolved, \League\Fractal\TransformerAbstract::class)
            ) {
                return $resolved;
            }
        }

        return null;
    }

    private function analyzeTransformer(string $transformerClass): ?SchemaObject
    {
        try {
            $ref = new \ReflectionClass($transformerClass);
            $fileName = $ref->getFileName();
            if ($fileName === false || ! file_exists($fileName)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        // Parse the transformer file's AST
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $stmts = $parser->parse(file_get_contents($fileName));
        if ($stmts === null) {
            return null;
        }

        // Find the transform() method
        $nodeFinder = new NodeFinder;
        $methods = $nodeFinder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            if ($method->name->toString() !== 'transform') {
                continue;
            }

            return $this->extractSchemaFromTransformMethod($method);
        }

        return null;
    }

    private function extractSchemaFromTransformMethod(Node\Stmt\ClassMethod $method): ?SchemaObject
    {
        $nodeFinder = new NodeFinder;
        $returns = $nodeFinder->findInstanceOf($method->stmts ?? [], Return_::class);

        foreach ($returns as $return) {
            if (! $return->expr instanceof Node\Expr\Array_) {
                continue;
            }

            $properties = [];
            foreach ($return->expr->items as $item) {
                if (! $item instanceof ArrayItem || ! $item->key instanceof String_) {
                    continue;
                }

                $properties[$item->key->value] = $this->inferType($item->value);
            }

            if (! empty($properties)) {
                return SchemaObject::object($properties);
            }
        }

        return null;
    }

    private function inferType(Node\Expr $expr): SchemaObject
    {
        // $model->id, $model->count — infer from property name
        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->name instanceof Node\Identifier
        ) {
            return $this->inferFromName($expr->name->toString());
        }

        // (int) $value, (bool) $value
        if ($expr instanceof Node\Expr\Cast\Int_) {
            return SchemaObject::integer();
        }
        if ($expr instanceof Node\Expr\Cast\Bool_) {
            return SchemaObject::boolean();
        }
        if ($expr instanceof Node\Expr\Cast\Double) {
            return SchemaObject::number('double');
        }

        return SchemaObject::string();
    }

    private function inferFromName(string $name): SchemaObject
    {
        return match (true) {
            $name === 'id' => SchemaObject::integer(),
            str_ends_with($name, '_id') => SchemaObject::integer(),
            str_ends_with($name, '_count') => SchemaObject::integer(),
            str_starts_with($name, 'is_') || str_starts_with($name, 'has_') => SchemaObject::boolean(),
            str_ends_with($name, '_at') => SchemaObject::string('date-time'),
            str_contains($name, 'email') => SchemaObject::string('email'),
            str_contains($name, 'url') || str_contains($name, 'link') => SchemaObject::string('uri'),
            str_contains($name, 'uuid') => SchemaObject::string('uuid'),
            str_contains($name, 'price') || str_contains($name, 'amount') => SchemaObject::number('double'),
            default => SchemaObject::string(),
        };
    }

    private function resolveClassName(string $shortName, AnalysisContext $ctx): ?string
    {
        // Try fully qualified
        if (class_exists($shortName)) {
            return $shortName;
        }

        // Try same namespace as controller
        $controllerClass = $ctx->controllerClass();
        if ($controllerClass !== null) {
            $ns = substr($controllerClass, 0, (int) strrpos($controllerClass, '\\'));
            $fqcn = $ns . '\\' . $shortName;
            if (class_exists($fqcn)) {
                return $fqcn;
            }

            // Try Transformers sub-namespace
            $parentNs = substr($ns, 0, (int) strrpos($ns, '\\'));
            $fqcn = $parentNs . '\\Transformers\\' . $shortName;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }
}
```

#### Versioning Headers

Detects API versioning strategies and documents the version parameter on each operation.

```php
<?php

namespace App\Documentation;

use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class VersioningHeaderPlugin implements Plugin, OperationTransformer
{
    public function __construct(
        private string $headerName = 'X-API-Version',
        private array $supportedVersions = ['2024-01-01', '2025-01-01'],
        private ?string $defaultVersion = null,
    ) {
        $this->defaultVersion ??= end($this->supportedVersions) ?: null;
    }

    public function name(): string
    {
        return 'versioning-header';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addOperationTransformer($this, 25);
    }

    public function priority(): int
    {
        return 25;
    }

    public function transform(array $operation, AnalysisContext $ctx): array
    {
        // Only add to routes that match your versioned API prefix
        if (! str_starts_with($ctx->route->uri, 'api/')) {
            return $operation;
        }

        $operation['parameters'] ??= [];
        $operation['parameters'][] = [
            'name' => $this->headerName,
            'in' => 'header',
            'required' => false,
            'description' => "API version. Defaults to `{$this->defaultVersion}` if omitted.",
            'schema' => [
                'type' => 'string',
                'enum' => $this->supportedVersions,
                'default' => $this->defaultVersion,
            ],
        ];

        return $operation;
    }
}
```

Register with custom configuration:

```php
// config/api-documentation.php
'plugins' => [
    new App\Documentation\VersioningHeaderPlugin(
        headerName: 'X-API-Version',
        supportedVersions: ['2024-01-01', '2024-07-01', '2025-01-01'],
    ),
],
```

#### Cache Control Headers

Detects caching middleware and documents conditional request headers (`ETag`, `If-None-Match`).

```php
<?php

namespace App\Documentation;

use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;

class CacheHeaderPlugin implements Plugin, OperationTransformer
{
    /**
     * Middleware names that indicate the response supports caching.
     */
    private const CACHE_MIDDLEWARE = [
        'cache.headers',
        'etag',
        'last-modified',
    ];

    public function name(): string
    {
        return 'cache-headers';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addOperationTransformer($this, 20);
    }

    public function priority(): int
    {
        return 20;
    }

    public function transform(array $operation, AnalysisContext $ctx): array
    {
        // Only apply to GET requests with caching middleware
        if (strtoupper($ctx->route->httpMethod()) !== 'GET') {
            return $operation;
        }

        $cacheMiddleware = $this->detectCacheMiddleware($ctx);
        if ($cacheMiddleware === null) {
            return $operation;
        }

        // Add conditional request headers
        $operation['parameters'] ??= [];
        $operation['parameters'][] = [
            'name' => 'If-None-Match',
            'in' => 'header',
            'required' => false,
            'description' => 'ETag value from a previous response. Returns 304 if unchanged.',
            'schema' => ['type' => 'string'],
        ];

        // Document the 304 response
        $operation['responses']['304'] = [
            'description' => 'Not Modified — the resource has not changed since the last request.',
        ];

        // Add cache-related response headers to the 200 response
        if (isset($operation['responses']['200'])) {
            $operation['responses']['200']['headers'] = array_merge(
                $operation['responses']['200']['headers'] ?? [],
                [
                    'ETag' => [
                        'description' => 'Entity tag for cache validation.',
                        'schema' => ['type' => 'string'],
                    ],
                    'Cache-Control' => [
                        'description' => 'Cache directives for the response.',
                        'schema' => ['type' => 'string'],
                        'example' => $this->buildCacheControlExample($cacheMiddleware),
                    ],
                ],
            );
        }

        return $operation;
    }

    private function detectCacheMiddleware(AnalysisContext $ctx): ?string
    {
        foreach ($ctx->route->middleware as $middleware) {
            $name = explode(':', $middleware)[0];
            if (in_array($name, self::CACHE_MIDDLEWARE, true)) {
                return $middleware;
            }
        }

        return null;
    }

    private function buildCacheControlExample(string $middleware): string
    {
        // Parse cache.headers:public;max_age=3600 format
        if (str_starts_with($middleware, 'cache.headers:')) {
            $directives = substr($middleware, 14);

            return str_replace(['_', ';'], ['-', ', '], $directives);
        }

        return 'public, max-age=3600';
    }
}
```

## Integrations

### Spatie Laravel Data

When `spatie/laravel-data` is installed, Data objects used as controller method parameters are automatically documented:

```php
class CreateUserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $bio = null,
    ) {}
}

// Automatically generates request body schema with name (required), email (required), bio (nullable)
public function store(CreateUserData $data): UserResource {}
```

### Spatie Laravel Query Builder

When `spatie/laravel-query-builder` is installed, allowed filters, sorts, and includes are extracted as query parameters:

```php
$users = QueryBuilder::for(User::class)
    ->allowedFilters(['name', 'email', 'status'])
    ->allowedSorts(['name', 'created_at'])
    ->allowedIncludes(['posts', 'profile'])
    ->paginate();
// Generates: filter[name], filter[email], filter[status], sort (enum), include (enum), page, per_page
```

### Laravel Actions

When `lorisleiva/laravel-actions` is installed, Action classes with the `AsController` trait are analyzed:

```php
class CreateUser
{
    use AsAction;
    use AsController;

    public function rules(): array
    {
        return ['name' => 'required|string', 'email' => 'required|email'];
    }

    public function handle(string $name, string $email): User {}
}
```

### JSON:API (timacdonald/json-api)

When `timacdonald/json-api` is installed, JSON:API resources produce proper `data`/`attributes`/`relationships` response schemas.

## CI/CD Integration

### Generate on Deploy

```bash
php artisan api:generate --format=json
```

### Block Breaking Changes

```bash
# Store current spec before changes
cp storage/app/public/api-documentation.json /tmp/old-spec.json

# Generate new spec
php artisan api:generate

# Compare - fails with exit code 1 if breaking changes detected
php artisan api:diff /tmp/old-spec.json storage/app/public/api-documentation.json --fail-on-breaking
```

### Validate Spec Quality

```bash
php artisan api:lint
# Returns non-zero exit code if errors are found
```

### Generate with Captures in CI

```bash
DOC_CAPTURE_MODE=true php artisan test
php artisan api:generate
```

## Security

The package is designed with security as a priority:

- **Production safe**: The capture middleware is gated behind environment checks and will never run in production, even if `DOC_CAPTURE_MODE` is accidentally set
- **Sensitive data redaction**: Passwords, tokens, API keys, credit card numbers, and SSNs are automatically redacted in captured examples
- **No runtime overhead**: The package only runs during documentation generation (`api:generate`) and optionally during testing (capture mode). It adds zero overhead to production request handling

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jakob Bennemann](https://github.com/jkbennemann)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
