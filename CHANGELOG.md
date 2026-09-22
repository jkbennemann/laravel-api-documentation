# Changelog

All notable changes to `laravel-api-documentation` will be documented in this file.

## [Unreleased]

### Added

#### v2 Architecture Rewrite
- **Plugin-based analysis engine** replacing the monolithic v1 services. Six focused interfaces (`RequestBodyExtractor`, `ResponseExtractor`, `QueryParameterExtractor`, `SecuritySchemeDetector`, `OperationTransformer`, `ExceptionSchemaProvider`) with priority-ordered chains.
- **Schema registry** with content-hash fingerprinting and automatic `$ref` deduplication.
- **Three-layer merge engine** combining attributes, static analysis, and runtime capture with configurable priority (`static_first` or `captured_first`).
- **AST caching** with file-based storage and mtime-keyed invalidation for fast repeated generation.

#### Built-in Plugins
- **BearerAuthPlugin** - Detects Bearer token auth from `auth:sanctum`, `auth:api`, `jwt.auth` middleware. Extracts OAuth scopes and Sanctum abilities.
- **PaginationPlugin** - Detects `paginate()`, `simplePaginate()`, `cursorPaginate()` calls and wraps responses with `data`/`meta`/`links` envelope.
- **CodeSamplePlugin** - Generates `x-codeSamples` in bash (cURL), JavaScript (fetch), PHP (Guzzle), and Python (requests).
- **SpatieDataPlugin** - Extracts request schemas from Spatie Data DTOs with nested DTO and `Lazy` type support.
- **SpatieQueryBuilderPlugin** - Extracts `filter[]`, `sort`, `include`, `fields` query parameters from QueryBuilder calls.
- **JsonApiPlugin** - Generates JSON:API response schemas (`data`/`attributes`/`relationships`) for `timacdonald/json-api`.
- **LaravelActionsPlugin** - Analyzes `lorisleiva/laravel-actions` classes with `AsController` trait.

#### New Commands
- `api:generate` - Generate OpenAPI spec with `--format`, `--domain`, `--route`, `--watch`, `--dev`, `--clear-cache` options.
- `api:lint` - Spec quality scoring (0-100) with coverage metrics and issue detection.
- `api:diff` - Breaking change detection between two specs with `--fail-on-breaking` for CI.
- `api:types` - TypeScript definition generation from component schemas.
- `api:clear-cache` - Clear AST and/or capture caches.
- `api:plugins` - List registered plugins with priorities and capabilities.

#### New Attributes
- `#[ExcludeFromDocs]` - Exclude a controller or method from documentation.
- `#[QueryParameter]` - Document query parameters with type, enum, and examples.
- `#[ResponseHeader]` - Document response headers.
- `#[RequestBody]` / `#[ResponseBody]` - Low-level request/response schema overrides.

#### Tag Documentation Support
- `#[Tag]` attribute now accepts an optional `description` parameter (Markdown supported).
- Config `tags` key for tag name-to-description mapping (overrides attribute descriptions).
- Config `tag_groups` for `x-tagGroups` vendor extension (ReDoc/Scalar sidebar grouping).
- Config `tag_groups_include_ungrouped` to control whether ungrouped tags get an automatic "Other" group or are hidden.
- Config `trait_tags` for documentation-only tags with `x-traitTag: true`.
- Config `external_docs` for spec-level `externalDocs` link.
- All tag documentation settings support per-domain overrides.

#### Output Formats
- **Postman Collection** export (`--format=postman`) with auth headers, path/query parameters, and request body examples.
- **YAML** output (`--format=yaml`) with built-in converter (no `ext-yaml` required).
- **TypeScript** definitions via `api:types` command.

#### Analysis Improvements
- Automatic error response detection: `422` from FormRequest, `401` from auth middleware, `403` from Gate/authorize calls, `404` from model binding, `429` from throttle middleware.
- Custom exception handler analysis for app-specific error schemas.
- PHPDoc `@queryParam` extraction and `@deprecated` detection with `@notDeprecated` exemption.
- Route constraint inference (numeric, UUID, slug patterns) for path parameter schemas.
- Route model binding key inference (`getRouteKeyName()`, explicit binding fields).
- `$request->get()`, `$request->integer()`, `$request->boolean()` detection as query parameters.
- File upload detection with automatic `multipart/form-data` content type.
- Conditional validation rule documentation (`required_if`, `required_with`, `required_without`).
- Synthetic example generation with field-name heuristics (`email`, `id`, `uuid`, date formats).
- Policy introspection for 403 responses with ability names.
- Rate limit header documentation from throttle middleware.

#### Documentation Viewers
- Built-in Swagger UI, ReDoc, and Scalar viewers with configurable routes and middleware.
- Hub page at `/documentation` redirecting to the default viewer.
- Alternative UI link appending for multi-viewer setups.

### Fixed
- **An `array` field with named child rules is documented as an object.** Laravel spells such a
  field twice — `'config' => ['required', 'array']` declares it, `'config.url' => [...]` describes
  what goes in it — and the first rule won. The schema then carried `type: array` alongside
  `properties`, which is not valid OpenAPI (`properties` is ignored on an array), so a generated
  client saw a list of strings where the API wants an object and could not build a valid request.
  A field with a `field.*` rule still describes items and stays an array.
- **A create that returns its PARENT is documented 200, not 201.** The 201 rule looked for a create
  call anywhere in the action, but Laravel keys the status on the model the RETURNED resource wraps.
  The ordinary nested-collection shape — `Step::create(...)` then
  `return new PolicyResource($policy)` — was documented 201 while the endpoint answers 200. The rule
  now follows the variable the returned resource is given, through a `DB::transaction` wrapper, and
  falls back to the old method-wide scan only when no variable can be identified.
- **A `#[DataResponse]` no longer suppresses the statuses it does not name.** The attribute
  previously turned return-statement analysis off entirely, so a method annotated for its success
  shape silently lost every error status its own code returns. Annotating the happy path is the
  common case, which made the status a caller meets most often the one missing from the document.
  The attribute still owns the statuses it declares.
- **Statuses raised by a guard helper are documented.** `abort()` was only found in the action's own
  body, so the very common `$this->enforcePlan($tenant)` / `$this->authorizeAccess()` opening left
  its 403 undocumented. Same-class calls on `$this` are now followed one level.

### Changed
- Configuration file restructured with `analysis`, `code_samples`, `smart_responses`, `smart_requests`, `error_responses`, `capture`, and domain-level overrides.
- Service provider rewritten for plugin-based architecture with auto-discovery from Composer `extra`.
- `CaptureApiResponseMiddleware` refactored with idempotent capture (unchanged schemas produce no file rewrites).

### Removed
- All v1 `Services/` classes (monolithic analyzers replaced by plugin-based architecture).
- `CaptureResponsesCommand`, `LaravelApiDocumentationCommand`, `ValidateDocumentationCommand` (replaced by `api:generate`, `api:clear-cache`, `api:lint`).
- `QUICK_REFERENCE.md` (superseded by comprehensive README).
