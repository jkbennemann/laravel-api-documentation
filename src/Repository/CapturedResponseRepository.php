<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Repository;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

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

    public function getForRoute(string $uri, string $method): ?array
    {
        $filename = $this->generateFilename($uri, $method);
        $filepath = $this->storagePath.'/'.$filename;

        if (! File::exists($filepath)) {
            return null;
        }

        $content = File::get($filepath);

        return json_decode($content, true);
    }

    public function getAll(): Collection
    {
        if (! File::exists($this->storagePath)) {
            return collect([]);
        }

        $files = File::files($this->storagePath);

        return collect($files)->map(function ($file) {
            $content = File::get($file->getPathname());
            $data = json_decode($content, true);

            if (! $data) {
                return null;
            }

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

    public function exists(string $uri, string $method): bool
    {
        $filename = $this->generateFilename($uri, $method);
        $filepath = $this->storagePath.'/'.$filename;

        return File::exists($filepath);
    }

    public function storeCapture(string $uri, string $method, int $statusCode, array $data): void
    {
        $filename = $this->generateFilename($uri, $method);
        $filepath = $this->storagePath.'/'.$filename;

        $this->ensureStorageExists();

        $existing = [];
        if (File::exists($filepath)) {
            $existing = json_decode(File::get($filepath), true) ?? [];
        }

        $existing[(string) $statusCode] = $data;

        File::put(
            $filepath,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function deleteForRoute(string $uri, string $method): bool
    {
        $filename = $this->generateFilename($uri, $method);
        $filepath = $this->storagePath.'/'.$filename;

        if (File::exists($filepath)) {
            return File::delete($filepath);
        }

        return false;
    }

    public function clearAll(): int
    {
        if (! File::exists($this->storagePath)) {
            return 0;
        }

        $files = File::files($this->storagePath);
        $count = count($files);

        foreach ($files as $file) {
            File::delete($file->getPathname());
        }

        return $count;
    }

    public function getStatistics(): array
    {
        $all = $this->getAll();

        $stats = [
            'total_routes' => $all->count(),
            'total_responses' => 0,
            'by_method' => [],
            'by_status' => [],
        ];

        foreach ($all as $item) {
            $method = $item['method'];
            $responses = $item['responses'];

            $stats['by_method'][$method] = ($stats['by_method'][$method] ?? 0) + 1;

            foreach ($responses as $status => $response) {
                $stats['total_responses']++;
                $stats['by_status'][$status] = ($stats['by_status'][$status] ?? 0) + 1;
            }
        }

        return $stats;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function ensureStorageExists(): void
    {
        if (! File::exists($this->storagePath)) {
            File::makeDirectory($this->storagePath, 0755, true);
        }
    }

    public function isStale(string $uri, string $method, int $hours = 24): bool
    {
        $responses = $this->getForRoute($uri, $method);
        if (! $responses) {
            return true;
        }

        $timestamps = array_column($responses, 'captured_at');
        $lastCapture = max($timestamps);

        try {
            $captureTime = new \DateTimeImmutable($lastCapture);
            $threshold = new \DateTimeImmutable("-{$hours} hours");

            return $captureTime < $threshold;
        } catch (\Throwable) {
            return true;
        }
    }

    private function generateFilename(string $uri, string $method): string
    {
        $method = strtolower($method);
        $uri = preg_replace('/\{[^}]+\}/', 'param', $uri);
        $uri = str_replace(['/', '.', ':', '-'], '_', $uri);
        $uri = trim($uri, '_');

        return $method.'_'.$uri.'.json';
    }

    private function parseFilename(string $basename): array
    {
        $parts = explode('_', $basename, 2);

        return [$parts[0] ?? 'get', $parts[1] ?? ''];
    }
}
