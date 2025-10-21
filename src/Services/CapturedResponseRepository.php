<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Collection;

/**
 * Repository for managing captured API responses
 */
class CapturedResponseRepository
{
    private string $storagePath;

    public function __construct()
    {
        $this->storagePath = config(
            'api-documentation.capture.storage_path',
            base_path('.schemas/responses')
        );
    }

    /**
     * Get captured responses for a specific route
     */
    public function getForRoute(string $uri, string $method): ?array
    {
        $filename = $this->generateFilename($uri, $method);
        $filepath = $this->storagePath . '/' . $filename;

        if (!File::exists($filepath)) {
            return null;
        }

        $content = File::get($filepath);
        return json_decode($content, true);
    }

    /**
     * Get all captured responses
     */
    public function getAll(): Collection
    {
        if (!File::exists($this->storagePath)) {
            return collect([]);
        }

        $files = File::files($this->storagePath);

        return collect($files)->map(function ($file) {
            $content = File::get($file->getPathname());
            $data = json_decode($content, true);

            if (!$data) {
                return null;
            }

            // Extract route info from filename
            $basename = $file->getBasename('.json');
            [$method, $route] = $this->parseFilename($basename);

            return [
                'route' => $route,
                'method' => strtoupper($method),
                'file' => $file->getFilename(),
                'responses' => $data,
            ];
        })->filter();
    }

    /**
     * Check if captured response exists for route
     */
    public function exists(string $uri, string $method): bool
    {
        $filename = $this->generateFilename($uri, $method);
        $filepath = $this->storagePath . '/' . $filename;

        return File::exists($filepath);
    }

    /**
     * Delete captured responses for a route
     */
    public function deleteForRoute(string $uri, string $method): bool
    {
        $filename = $this->generateFilename($uri, $method);
        $filepath = $this->storagePath . '/' . $filename;

        if (File::exists($filepath)) {
            return File::delete($filepath);
        }

        return false;
    }

    /**
     * Clear all captured responses
     */
    public function clearAll(): int
    {
        if (!File::exists($this->storagePath)) {
            return 0;
        }

        $files = File::files($this->storagePath);
        $count = count($files);

        foreach ($files as $file) {
            File::delete($file->getPathname());
        }

        return $count;
    }

    /**
     * Get statistics about captured responses
     */
    public function getStatistics(): array
    {
        $all = $this->getAll();

        $stats = [
            'total_routes' => $all->count(),
            'total_responses' => 0,
            'by_method' => [],
            'by_status' => [],
            'coverage' => [],
        ];

        foreach ($all as $item) {
            $method = $item['method'];
            $responses = $item['responses'];

            // Count by method
            $stats['by_method'][$method] = ($stats['by_method'][$method] ?? 0) + 1;

            // Count by status code
            foreach ($responses as $status => $response) {
                $stats['total_responses']++;
                $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
            }
        }

        return $stats;
    }

    /**
     * Generate filename from route URI and method
     */
    private function generateFilename(string $uri, string $method): string
    {
        $method = strtolower($method);

        // Replace parameter placeholders with generic names
        $uri = preg_replace('/\{[^}]+\}/', 'param', $uri);

        // Replace slashes and special characters
        $uri = str_replace(['/', '.', ':', '-'], '_', $uri);

        // Remove leading/trailing underscores
        $uri = trim($uri, '_');

        // Build filename: method_route.json
        return $method . '_' . $uri . '.json';
    }

    /**
     * Parse filename back to method and route
     */
    private function parseFilename(string $basename): array
    {
        // Extract method (first part before underscore)
        $parts = explode('_', $basename, 2);

        $method = $parts[0] ?? 'get';
        $route = $parts[1] ?? '';

        // Reconstruct route URI (approximate)
        $route = str_replace('_', '/', $route);

        return [$method, $route];
    }

    /**
     * Get captured response for specific status code
     */
    public function getResponseForStatus(string $uri, string $method, int $statusCode): ?array
    {
        $responses = $this->getForRoute($uri, $method);

        if (!$responses) {
            return null;
        }

        return $responses[(string) $statusCode] ?? null;
    }

    /**
     * Get all status codes captured for a route
     */
    public function getStatusCodes(string $uri, string $method): array
    {
        $responses = $this->getForRoute($uri, $method);

        if (!$responses) {
            return [];
        }

        return array_keys($responses);
    }

    /**
     * Get last capture timestamp for a route
     */
    public function getLastCaptureTime(string $uri, string $method): ?string
    {
        $responses = $this->getForRoute($uri, $method);

        if (!$responses) {
            return null;
        }

        // Get the most recent capture timestamp
        $timestamps = array_column($responses, 'captured_at');

        if (empty($timestamps)) {
            return null;
        }

        return max($timestamps);
    }

    /**
     * Check if capture is stale (older than specified hours)
     */
    public function isStale(string $uri, string $method, int $hours = 24): bool
    {
        $lastCapture = $this->getLastCaptureTime($uri, $method);

        if (!$lastCapture) {
            return true;
        }

        $captureTime = \Carbon\Carbon::parse($lastCapture);
        $thresholdTime = now()->subHours($hours);

        return $captureTime->lt($thresholdTime);
    }

    /**
     * Get storage path
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * Ensure storage directory exists
     */
    public function ensureStorageExists(): void
    {
        if (!File::exists($this->storagePath)) {
            File::makeDirectory($this->storagePath, 0755, true);
        }
    }
}
