# Changelog

All notable changes to `laravel-api-documentation` will be documented in this file.

## 2025-10-24

### Added

#### Request Example Capture from Test Suite

- **Automatic Request Body and Query Parameter Capture**: The capture middleware now captures request data alongside responses
  - **Location**: `src/Middleware/CaptureApiResponseMiddleware.php`
  - **Captured data includes:**
    - **POST/PUT/PATCH requests**: Request body (JSON or form data)
    - **GET/HEAD requests**: Query parameters
    - **All requests**: Request headers (Content-Type, Accept, etc.)
  - **Smart method detection:**
    - GET/HEAD: Captures query parameters only
    - POST/PUT/PATCH: Captures request body + optional query params
    - DELETE: Captures request body (if any) + query params
  - **Automatic schema inference**: Generates OpenAPI schemas from captured request data
  - **Sanitization**: Sensitive data (passwords, tokens, secrets) automatically redacted

- **OpenAPI Integration for Request Examples**: Request examples from tests are automatically added to generated documentation
  - **Location**: `src/Services/OpenApi.php`
  - **Features:**
    - `enhanceRequestBodyWithCapturedExamples()`: Adds captured request bodies as examples for POST/PUT/PATCH endpoints
    - `enhanceQueryParametersWithCapturedExamples()`: Adds captured query param values as examples for GET endpoints
    - `findQueryParamValue()`: Smart matching for array notation (e.g., `filter[service]` → `filter.service`)
  - **Example output:**
    ```json
    {
      "requestBody": {
        "content": {
          "application/json": {
            "schema": { "type": "object", "properties": {...} },
            "examples": {
              "captured_201": {
                "summary": "Example from test suite (status 201)",
                "value": {
                  "service": "managed",
                  "plan": "pro",
                  "billing_interval": "monthly",
                  "addons": ["ssl", "backup"]
                }
              }
            }
          }
        }
      }
    }
    ```

- **Configuration**: Added `requests` capture option
  - **Location**: `config/api-documentation.php`
  - **Config structure:**
    ```php
    'capture' => [
        'requests' => true,   // NEW: Capture request bodies and query parameters
        'responses' => true,  // Capture response bodies
        'headers' => true,    // Capture request/response headers
        'examples' => true,   // Generate examples from captured data
    ],
    ```

### Benefits

- **Zero maintenance**: Request examples evolve automatically as your test suite evolves
- **Always accurate**: Examples are exactly what your tests actually send
- **Multiple scenarios**: Captures success cases, validation errors, and edge cases
- **Test-driven documentation**: Better tests = better documentation
- **Comprehensive coverage**: Both GET (query params) and POST/PUT/PATCH (request bodies) supported

## 2025-10-21

### Added

#### Enhanced Request Parameter Documentation

- **Enum Value Extraction from Rule::in()**: Added automatic extraction of enum values from `Rule::in()` validation rules
  - Location: `src/Services/RequestAnalyzer.php`
  - **Supported patterns:**
    - Direct arrays: `Rule::in(['value1', 'value2', 'value3'])`
    - PHP 8.1+ enums: `Rule::in(MyEnum::cases())` or `Rule::in(MyEnum::values())`
    - Class constants: `Rule::in(MyEnum::ALL_VALUES)`
  - **Automatic class name resolution:**
    - Parses `use` statements from FormRequest files
    - Resolves short class names to fully qualified names
    - Example: `BoxPerformanceLevelType` → `App\Enums\BoxPerformanceLevelType`
  - **Enum value extraction methods:**
    - `extractEnumValuesFromRuleInArgument()`: Main extraction logic
    - `extractValuesFromPhpEnum()`: PHP 8.1+ backed enum support
    - `extractValuesFromCustomEnum()`: Custom enum classes with `values()` method
    - `extractUseStatementsFromAst()`: AST-based use statement parsing
  - **Generated OpenAPI schema:**
    - Type: Inferred from validation rules
    - Enum: Array of all possible values
    - Description: "Must be one of: {values}"
    - Example: First enum value
  - **Before/After comparison:**
    ```json
    // Before (without enum extraction)
    {
      "type": "string",
      "description": "Can be null. Must be a string."
    }

    // After (with automatic enum extraction)
    {
      "type": "string",
      "description": "Can be null. Must be a string. Must be one of: mini, starter, fully_managed, pro, pro_xl, business, business_xl, business_xxl, enterprise, enteprise_xl.",
      "enum": ["mini", "starter", "fully_managed", "pro", "pro_xl", "business", "business_xl", "business_xxl", "enterprise", "enteprise_xl"],
      "example": "mini"
    }
    ```

#### Runtime Response Capture System
- **CaptureApiResponseMiddleware**: Middleware that captures actual API responses during testing for documentation generation
  - Location: `src/Middleware/CaptureApiResponseMiddleware.php`
  - Automatically captures response schemas during test execution
  - Production-safe with explicit environment checks
  - Stores captured responses in `.schemas/responses/` directory
  - Supports sensitive data sanitization

- **CapturedResponseRepository**: Service for managing stored captured responses
  - Location: `src/Services/CapturedResponseRepository.php`
  - Query responses by route and method
  - Generate capture statistics
  - Detect stale captures

- **CaptureResponsesCommand**: Artisan command to run tests with capture enabled
  - Location: `src/Commands/CaptureResponsesCommand.php`
  - Command: `php artisan documentation:capture`
  - Options: `--clear`, `--stats`

- **ValidateDocumentationCommand**: Command to validate documentation accuracy
  - Location: `src/Commands/ValidateDocumentationCommand.php`
  - Command: `php artisan documentation:validate`
  - Options: `--strict`, `--min-accuracy`

- **DocumentationValidator**: Service for validating static vs captured data
  - Location: `src/Services/DocumentationValidator.php`

#### Enhanced Response Analysis

- **Shorthand Array Notation Support**: Added support for shorthand property definitions in `DataResponse` attributes
  - Format: `['type', nullable, 'description', 'example']`
  - Example: `['access_token' => ['string', null, 'Refreshed JWT token', 'ey**.***.***']]`
  - Location: `src/Services/EnhancedResponseAnalyzer.php:1303-1348`
  - Methods: `isShorthandPropertyDefinition()`, `parseShorthandPropertyDefinition()`

- **Recursive Nested Property Schema Building**: Fixed nested object/array schema preservation in OpenAPI output
  - Location: `src/Services/OpenApi.php:580-634`
  - Method: `buildResponseSchema()`
  - Now recursively processes properties and items at any depth
  - Preserves format, nullable, required, enum fields

- **Array Type Handling**: Added proper `items` schema for array types
  - Spatie Data DTOs with `array` type now include `items: {type: 'object'}`
  - Location: `src/Services/EnhancedResponseAnalyzer.php:827-830`

- **DTO Type Introspection**: Enhanced type detection using DTO reflection
  - Extracts DTO class from PHPDoc comments (`@var ClassName $variable`)
  - Resolves fully qualified class names using `use` statements
  - Reflects on DTO properties to determine actual PHP types
  - Location: `src/Services/ResponseAnalyzer.php:1478-1513, 1662-1695`
  - Methods: `extractDtoClassFromMethodBody()`, `resolveClassNameWithUseStatements()`, `getPropertyTypeFromDto()`

- **Property Name Heuristics**: Added intelligent fallback for common array property names
  - Properties named `meta`, `items`, `data`, `attributes`, `properties`, `tags`, `categories` automatically detected as arrays
  - Location: `src/Services/ResponseAnalyzer.php:1702-1705`

### Fixed

- **Wildcard Array Documentation**: Fixed issue where array validation rules with wildcard notation (e.g., `items.*`) were not properly documented
  - **Problem**: Fields like `'items.*' => ['string']` were documented as objects with a `*` property instead of arrays with items schema
  - **Root Cause**: The `transformParameter` method in `RouteComposition` didn't preserve `items` or `properties` schemas from `RequestAnalyzer`
  - **Solution**: Enhanced `transformParameter` to preserve three schema types:
    - `items`: For arrays (e.g., `items.*`)
    - `properties`: For nested objects (e.g., `wordpress.version`)
    - `parameters`: Legacy structure support
  - **Files Modified**:
    - `src/Services/RouteComposition.php:766-789` - Added items and properties preservation
    - `src/Services/RequestAnalyzer.php:1084-1140` - Enhanced wildcard detection for `items.*`
    - `src/Services/OpenApi.php:607-651` - Added items schema handling in request body builder
  - **Before**: `{"items": {"type": "array", "description": "..."}}`
  - **After**: `{"items": {"type": "array", "items": {"type": "string"}, "description": "..."}}`

- **String vs Array Schema Handling**: Fixed issue where captured schemas with string property types (e.g., `"properties": "string"`) weren't handled correctly
  - Added type checking before recursive processing
  - Location: `src/Services/OpenApi.php:568-571, 581-584`

- **Refresh Token Endpoint Schema**: Fixed incorrect schema where `access_token` appeared as object with numeric indices
  - Before: `{"access_token": {"0": "string", "1": "Unknown Type: mixed", ...}}`
  - After: `{"access_token": {"type": "string", "description": "Refreshed JWT token"}}`

- **Property Name Regex**: Fixed regex pattern to match properties with trailing characters
  - Changed from `/\$[^->]+->(\w+)$/` to `/\$[^->]+->(\w+)/`
  - Now matches `$subscription->meta,` and `$subscription->meta;` patterns

- **Nested Query Parameters**: Fixed issue where nested query parameters (e.g., `filter.service`) were not properly documented in GET requests
  - **Problem**: Validation rules like `'filter.service' => ['sometimes', 'string']` were documented as a single generic object parameter named `filter`
  - **Root Cause**: OpenApi builder didn't handle `properties` structure for GET request query parameters
  - **Solution**: Enhanced query parameter processing to expand nested objects into individual parameters using array notation
  - **Files Modified**:
    - `src/Services/OpenApi.php:284-341` - Added nested property expansion for query parameters
  - **Before**: Single parameter `filter` with `type: object`
  - **After**: Individual parameter `filter[service]` with `type: string` and full description
  - **Example Output**:
    ```json
    {
      "name": "filter[service]",
      "in": "query",
      "description": "Optional field that is validated only when present. Must be a string.",
      "required": false,
      "schema": {
        "type": "string"
      }
    }
    ```

### Changed

#### Documentation Builder Enhancements

- **Captured Response Integration**: DocumentationBuilder now merges static analysis with captured response data
  - Location: `src/Services/DocumentationBuilder.php:93-147`
  - Method: `enhanceRoutesWithCapturedData()`
  - Strategies: `captured_priority` (default), `static_priority`, `merge_both`
  - Config: `api-documentation.generation.merge_strategy`

- **Response Format Conversion**: Added converter for captured response format to OpenAPI format
  - Location: `src/Services/DocumentationBuilder.php:149-177`
  - Method: `convertCapturedToOpenApiFormat()`
  - Properly extracts content-type from headers
  - Preserves examples, properties, items, required fields

#### Configuration Updates

- **Capture Configuration**: New config section for response capture
  - Location: `config/api-documentation.php`
  - Options:
    - `capture.enabled`: Enable/disable capture mode
    - `capture.storage_path`: Where to store captured responses
    - `capture.sanitize.sensitive_keys`: Keys to redact
    - `capture.rules.max_size`: Maximum response size to capture
    - `capture.rules.exclude_routes`: Routes to skip

- **Generation Configuration**: New options for documentation generation
  - `generation.use_captured`: Enable captured response usage
  - `generation.merge_strategy`: How to merge static and captured data
  - `generation.fallback_to_static`: Fallback when no capture available
  - `generation.warn_missing_captures`: Log warnings for missing captures

### Technical Details

#### File Structure Changes

```
src/
├── Commands/
│   ├── CaptureResponsesCommand.php (NEW)
│   └── ValidateDocumentationCommand.php (NEW)
├── Middleware/
│   └── CaptureApiResponseMiddleware.php (NEW)
├── Services/
│   ├── CapturedResponseRepository.php (NEW)
│   ├── DocumentationValidator.php (NEW)
│   ├── DocumentationBuilder.php (MODIFIED)
│   ├── EnhancedResponseAnalyzer.php (MODIFIED)
│   ├── OpenApi.php (MODIFIED)
│   └── ResponseAnalyzer.php (MODIFIED)
└── LaravelApiDocumentationServiceProvider.php (MODIFIED)
```

#### Dependencies

- No new package dependencies added
- Compatible with Laravel 10.x, 11.x, 12.x
- PHP 8.0+ (uses `match` expressions)

## Workflow Integration

### Development Workflow

```bash
# 1. Enable capture mode
export DOC_CAPTURE_MODE=true

# 2. Run tests (captures responses automatically)
composer test

# 3. Generate documentation (uses captured + static analysis)
php artisan documentation:generate

# 4. Validate accuracy (optional)
php artisan documentation:validate --min-accuracy=95
```

### Multi-Version Documentation

The package now supports generating multiple documentation versions for different APIs:

```php
// config/api-documentation.php
'files' => [
    'api' => [
        'name' => 'API Gateway Documentation',
        'filename' => 'api-documentation.json',
        'process' => true,
    ],
    'public-api' => [
        'name' => 'Public API Documentation',
        'filename' => 'public-api-documentation.json',
        'process' => true,
    ],
],

'domains' => [
    'api' => [
        'title' => 'API Gateway Documentation',
        'main' => env('APP_URL'),
        'servers' => [...],
    ],
    'public-api' => [
        'title' => 'Public API Documentation',
        'main' => env('PUBLIC_API_URL'),
        'servers' => [...],
    ],
],
```

### Controller Attribute Usage

```php
use JkBennemann\LaravelApiDocumentation\Attributes\DocumentationFile;

#[DocumentationFile('public-api')]
class PublicApiController extends Controller
{
    // Routes appear only in public-api-documentation.json
}
```

## Migration Notes

### Breaking Changes

None. All changes are backward compatible.

### Deprecated Features

None.

### New Requirements

- **Storage Symlink**: Ensure `php artisan storage:link` has been run for documentation files to be accessible via web
- **Environment Variable**: Optionally set `DOC_CAPTURE_MODE=true` in `.env` for automatic capture during tests

## Known Issues & Limitations

### Static Analysis Limitations

- **Array Type Detection**: Static analysis may incorrectly identify array fields as strings when:
  - DTO property types can't be resolved due to complex namespacing
  - Property access patterns don't match expected formats
  - **Solution**: Use runtime response capture for 100% accuracy

### Workarounds

1. **For Array Type Issues**: Run tests with `DOC_CAPTURE_MODE=true` to capture real response structures
2. **For Missing Nested Properties**: Ensure PHPDoc comments include `@var` annotations in Resource `toArray()` methods
3. **For Complex DTOs**: Add `#[Parameter]` attributes to DTO properties for explicit type hints

## Performance Impact

- **Zero Production Overhead**: Capture middleware only runs when `DOC_CAPTURE_MODE=true` and never in production
- **Test Suite**: Minimal overhead (~50-100ms per test) when capture is enabled
- **Documentation Generation**: Slight increase in generation time when processing captured responses

## Security Considerations

- **Sensitive Data Sanitization**: Automatic redaction of sensitive keys (passwords, tokens, secrets)
- **Production Safety**: Multiple checks prevent capture from running in production
- **File Permissions**: Captured response files are stored in `.schemas/` (should be gitignored for sensitive data)

## Future Enhancements

- [ ] PDF generation for commission statements
- [ ] Email notifications for documentation updates
- [ ] Advanced diff viewer for schema changes
- [ ] Automatic validation in CI/CD pipelines
- [ ] Integration with API testing tools

---
