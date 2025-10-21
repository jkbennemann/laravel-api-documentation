<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Captures API responses during testing for documentation generation
 *
 * IMPORTANT: Only runs in local/testing environments, never in production
 */
class CaptureApiResponseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only capture in safe environments
        if ($this->shouldCapture($request)) {
            try {
                $this->captureResponse($request, $response);
            } catch (Throwable $e) {
                // Silently fail - don't break the application
                // Log if in testing mode
                if (app()->environment('testing')) {
                    logger()->warning('Failed to capture API response: ' . $e->getMessage());
                }
            }
        }

        return $response;
    }

    /**
     * Determine if we should capture this request/response
     */
    private function shouldCapture(Request $request): bool
    {
        // Safety check: NEVER run in production
        if (app()->environment('production')) {
            return false;
        }

        // Must be explicitly enabled
        $enabled = config('api-documentation.capture.enabled', false);

        // Check environment variable directly as fallback
        if (!$enabled && !env('DOC_CAPTURE_MODE', false)) {
            return false;
        }

        // Only capture if we have a route
        if (!$request->route()) {
            return false;
        }

        // Check if this is an API route we care about
        if (!$this->isApiRoute($request)) {
            return false;
        }

        // Check if route is excluded
        if ($this->isExcludedRoute($request)) {
            return false;
        }

        return true;
    }

    /**
     * Check if this is an API route
     */
    private function isApiRoute(Request $request): bool
    {
        $route = $request->route();

        // Check if route URI starts with api/
        if (str_starts_with($route->uri(), 'api/')) {
            return true;
        }

        // Check if route has api middleware
        $middleware = $route->middleware();
        if (in_array('api', $middleware)) {
            return true;
        }

        return false;
    }

    /**
     * Check if route should be excluded from capture
     */
    private function isExcludedRoute(Request $request): bool
    {
        $excludedRoutes = config('api-documentation.capture.rules.exclude_routes', []);
        $uri = $request->route()->uri();

        foreach ($excludedRoutes as $pattern) {
            if (str_contains($pattern, '*')) {
                // Wildcard match
                $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
                if (preg_match($regex, $uri)) {
                    return true;
                }
            } elseif ($uri === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Capture the response and store it
     */
    private function captureResponse(Request $request, Response $response): void
    {
        $route = $request->route();

        // Build capture data
        $capture = [
            'route' => $route->uri(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'captured_at' => now()->toIso8601String(),
        ];

        // Add headers if enabled
        if (config('api-documentation.capture.capture.headers', true)) {
            $capture['headers'] = $this->cleanHeaders($response->headers->all());
        }

        // Add response schema and example
        if (config('api-documentation.capture.capture.responses', true)) {
            $content = $this->getResponseContent($response);

            if ($content !== null) {
                $capture['schema'] = $this->inferSchema($content);

                if (config('api-documentation.capture.capture.examples', true)) {
                    $capture['example'] = $this->sanitizeExample($content);
                }
            }
        }

        // Store the capture
        $this->storeCapture($route, $capture);
    }

    /**
     * Get response content as array
     */
    private function getResponseContent(Response $response): mixed
    {
        // Check response size limit
        $maxSize = config('api-documentation.capture.rules.max_size', 1024 * 100);
        $content = $response->getContent();

        if (strlen($content) > $maxSize) {
            return null; // Skip large responses
        }

        // Handle JSON responses
        if ($response instanceof JsonResponse) {
            return $response->getData(true);
        }

        // Try to decode JSON content
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Clean headers for documentation
     */
    private function cleanHeaders(array $headers): array
    {
        $cleaned = [];

        // Headers to include in documentation
        $includedHeaders = [
            'content-type',
            'x-ratelimit-limit',
            'x-ratelimit-remaining',
            'x-pagination-total',
            'x-pagination-page',
            'cache-control',
        ];

        foreach ($headers as $name => $values) {
            $lowerName = strtolower($name);
            if (in_array($lowerName, $includedHeaders)) {
                $cleaned[$name] = is_array($values) ? $values[0] : $values;
            }
        }

        return $cleaned;
    }

    /**
     * Infer OpenAPI schema from actual response data
     */
    private function inferSchema(mixed $data, int $depth = 0): array
    {
        // Prevent infinite recursion
        if ($depth > 10) {
            return ['type' => 'object'];
        }

        if (is_null($data)) {
            return ['type' => 'null', 'nullable' => true];
        }

        if (is_bool($data)) {
            return ['type' => 'boolean'];
        }

        if (is_int($data)) {
            return ['type' => 'integer'];
        }

        if (is_float($data)) {
            return ['type' => 'number', 'format' => 'float'];
        }

        if (is_string($data)) {
            $schema = ['type' => 'string'];

            // Detect format
            $format = $this->detectStringFormat($data);
            if ($format) {
                $schema['format'] = $format;
            }

            return $schema;
        }

        if (is_array($data)) {
            // Empty array
            if (empty($data)) {
                return [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                ];
            }

            // Check if it's an indexed array (list) or associative (object)
            $isSequential = array_keys($data) === range(0, count($data) - 1);

            if ($isSequential) {
                // It's a list/array
                return [
                    'type' => 'array',
                    'items' => $this->inferSchema($data[0], $depth + 1),
                ];
            } else {
                // It's an object
                $properties = [];
                $required = [];

                foreach ($data as $key => $value) {
                    $properties[$key] = $this->inferSchema($value, $depth + 1);

                    // Mark as required if not null
                    if ($value !== null) {
                        $required[] = $key;
                    }
                }

                $schema = [
                    'type' => 'object',
                    'properties' => $properties,
                ];

                if (!empty($required)) {
                    $schema['required'] = $required;
                }

                return $schema;
            }
        }

        return ['type' => 'object'];
    }

    /**
     * Detect string format for better schema accuracy
     */
    private function detectStringFormat(string $value): ?string
    {
        // Empty string
        if (empty($value)) {
            return null;
        }

        // UUID (v4)
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            return 'uuid';
        }

        // Email
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        // URL
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return 'uri';
        }

        // ISO 8601 Date-time
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/', $value)) {
            return 'date-time';
        }

        // Date only
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return 'date';
        }

        // IPv4
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'ipv4';
        }

        // IPv6
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'ipv6';
        }

        return null;
    }

    /**
     * Sanitize example data (remove sensitive information)
     */
    private function sanitizeExample(mixed $data): mixed
    {
        if (!config('api-documentation.capture.sanitize.enabled', true)) {
            return $data;
        }

        if (!is_array($data)) {
            return $data;
        }

        $sensitiveKeys = config('api-documentation.capture.sanitize.sensitive_keys', [
            'password', 'token', 'secret', 'api_key', 'apiKey',
            'access_token', 'refresh_token', 'private_key',
            'authorization', 'x-api-key',
        ]);

        $redactedValue = config('api-documentation.capture.sanitize.redacted_value', '***REDACTED***');

        return $this->recursiveSanitize($data, $sensitiveKeys, $redactedValue);
    }

    /**
     * Recursively sanitize sensitive data
     */
    private function recursiveSanitize(array $data, array $sensitiveKeys, string $redactedValue): array
    {
        foreach ($data as $key => $value) {
            // Check if key is sensitive (case-insensitive)
            $lowerKey = strtolower($key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, strtolower($sensitiveKey))) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $data[$key] = $redactedValue;
            } elseif (is_array($value)) {
                $data[$key] = $this->recursiveSanitize($value, $sensitiveKeys, $redactedValue);
            }
        }

        return $data;
    }

    /**
     * Store captured response to filesystem
     */
    private function storeCapture($route, array $capture): void
    {
        $storagePath = config('api-documentation.capture.storage_path', base_path('.schemas/responses'));

        // Create storage directory if it doesn't exist
        if (!File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        // Generate filename from route
        $filename = $this->generateFilename($route);
        $filepath = $storagePath . '/' . $filename;

        // Load existing captures for this route
        $existing = [];
        if (File::exists($filepath)) {
            $existing = json_decode(File::get($filepath), true) ?? [];
        }

        // Add/update capture for this status code
        $statusCode = (string) $capture['status'];
        $existing[$statusCode] = $capture;

        // Write back to file
        File::put(
            $filepath,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Generate filename from route
     */
    private function generateFilename($route): string
    {
        $uri = $route->uri();
        $method = strtolower($route->methods()[0] ?? 'get');

        // Replace parameter placeholders with generic names
        $uri = preg_replace('/\{[^}]+\}/', 'param', $uri);

        // Replace slashes and special characters
        $uri = str_replace(['/', '.', ':', '-'], '_', $uri);

        // Build filename: method_route.json
        return $method . '_' . $uri . '.json';
    }
}
