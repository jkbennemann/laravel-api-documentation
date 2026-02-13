<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Output;

class JsonWriter
{
    /**
     * Write an OpenAPI spec array to a JSON file.
     */
    public function write(array $spec, string $path): void
    {
        $this->validateOutputPath($path);

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode(
            $spec,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        file_put_contents($path, $json);
    }

    private function validateOutputPath(string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw new \InvalidArgumentException("Invalid output file extension: {$extension}");
        }
    }

    /**
     * Convert spec to JSON string.
     */
    public function toJson(array $spec): string
    {
        return json_encode(
            $spec,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
