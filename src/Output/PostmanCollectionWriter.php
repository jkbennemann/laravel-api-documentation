<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Output;

class PostmanCollectionWriter
{
    /**
     * Convert an OpenAPI spec array to a Postman Collection v2.1 array.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function convert(array $spec): array
    {
        $info = $spec['info'] ?? [];
        $baseUrl = $this->extractBaseUrl($spec);

        $collection = [
            'info' => [
                'name' => $info['title'] ?? 'API Collection',
                'description' => $info['description'] ?? '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => $this->buildItems($spec, $baseUrl),
        ];

        // Add collection-level auth if security schemes exist
        $auth = $this->buildAuth($spec);
        if ($auth !== null) {
            $collection['auth'] = $auth;
        }

        // Add variables
        $collection['variable'] = [
            [
                'key' => 'baseUrl',
                'value' => $baseUrl,
                'type' => 'string',
            ],
        ];

        return $collection;
    }

    /**
     * Write the Postman collection to a JSON file.
     */
    public function write(array $spec, string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $collection = $this->convert($spec);

        $json = json_encode(
            $collection,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        file_put_contents($path, $json);
    }

    /**
     * Build Postman items grouped by tag (folders).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $spec, string $baseUrl): array
    {
        $folders = [];

        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $tag = $operation['tags'][0] ?? 'default';
                $item = $this->buildRequestItem($path, strtoupper($method), $operation, $spec);

                $folders[$tag][] = $item;
            }
        }

        // Convert to Postman folder format
        $items = [];
        foreach ($folders as $tag => $requests) {
            if (count($folders) === 1 && $tag === 'default') {
                // No folders needed for a single default tag
                return $requests;
            }

            $items[] = [
                'name' => $tag,
                'item' => $requests,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestItem(string $path, string $method, array $operation, array $spec): array
    {
        $item = [
            'name' => $operation['summary'] ?? "{$method} {$path}",
            'request' => [
                'method' => $method,
                'header' => $this->buildHeaders($operation),
                'url' => $this->buildUrl($path, $operation),
            ],
        ];

        if (isset($operation['description'])) {
            $item['request']['description'] = $operation['description'];
        }

        // Request body
        $body = $this->buildBody($operation, $spec);
        if ($body !== null) {
            $item['request']['body'] = $body;
        }

        // Auth
        if (! empty($operation['security'])) {
            $item['request']['auth'] = [
                'type' => 'bearer',
                'bearer' => [
                    ['key' => 'token', 'value' => '{{apiToken}}', 'type' => 'string'],
                ],
            ];
        }

        // Expected responses
        $responses = $this->buildResponses($operation);
        if (! empty($responses)) {
            $item['response'] = $responses;
        }

        return $item;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildHeaders(array $operation): array
    {
        $headers = [
            ['key' => 'Accept', 'value' => 'application/json'],
        ];

        $hasBody = isset($operation['requestBody']['content']['application/json']);
        if ($hasBody) {
            $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUrl(string $path, array $operation): array
    {
        // Convert OpenAPI path params {id} to Postman :id
        $rawPath = preg_replace('/\{(\w+)\??\}/', ':$1', $path);

        $url = [
            'raw' => '{{baseUrl}}'.rtrim($rawPath, '/'),
            'host' => ['{{baseUrl}}'],
            'path' => array_values(array_filter(explode('/', $rawPath))),
        ];

        // Query parameters
        $query = [];
        foreach ($operation['parameters'] ?? [] as $param) {
            if (($param['in'] ?? '') === 'query') {
                $query[] = [
                    'key' => $param['name'],
                    'value' => $this->extractParamExample($param),
                    'description' => $param['description'] ?? null,
                    'disabled' => ! ($param['required'] ?? false),
                ];
            }
        }

        if (! empty($query)) {
            $url['query'] = array_map(
                fn ($q) => array_filter($q, fn ($v) => $v !== null),
                $query
            );
        }

        // Path variables
        $variables = [];
        foreach ($operation['parameters'] ?? [] as $param) {
            if (($param['in'] ?? '') === 'path') {
                $variables[] = [
                    'key' => $param['name'],
                    'value' => $this->extractParamExample($param),
                    'description' => $param['description'] ?? null,
                ];
            }
        }

        if (! empty($variables)) {
            $url['variable'] = array_map(
                fn ($v) => array_filter($v, fn ($val) => $val !== null),
                $variables
            );
        }

        return $url;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildBody(array $operation, array $spec): ?array
    {
        $content = $operation['requestBody']['content']['application/json'] ?? null;
        if ($content === null) {
            return null;
        }

        $schema = $content['schema'] ?? [];
        $example = $content['example'] ?? $this->extractObjectExample($schema, $spec);

        if ($example === null) {
            return null;
        }

        return [
            'mode' => 'raw',
            'raw' => json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'options' => [
                'raw' => [
                    'language' => 'json',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildResponses(array $operation): array
    {
        $responses = [];

        foreach ($operation['responses'] ?? [] as $statusCode => $response) {
            $item = [
                'name' => $response['description'] ?? "Response {$statusCode}",
                'status' => $response['description'] ?? 'OK',
                'code' => (int) $statusCode,
                'header' => [],
                'body' => '',
            ];

            // Extract response body example
            $content = $response['content']['application/json'] ?? null;
            if ($content !== null) {
                $example = $content['example'] ?? $this->extractObjectExample($content['schema'] ?? [], []);
                if ($example !== null) {
                    $item['body'] = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }
            }

            $responses[] = $item;
        }

        return $responses;
    }

    /**
     * Extract an example value for a parameter.
     */
    private function extractParamExample(array $param): string
    {
        if (isset($param['example'])) {
            return (string) $param['example'];
        }

        $schema = $param['schema'] ?? [];
        if (isset($schema['example'])) {
            return (string) $schema['example'];
        }

        return '';
    }

    /**
     * Build an example object from schema properties.
     */
    private function extractObjectExample(array $schema, array $spec): mixed
    {
        // Resolve $ref
        if (isset($schema['$ref'])) {
            $refName = str_replace('#/components/schemas/', '', $schema['$ref']);
            $schema = $spec['components']['schemas'][$refName] ?? [];
        }

        if (isset($schema['example'])) {
            return $schema['example'];
        }

        if (($schema['type'] ?? null) === 'object' && isset($schema['properties'])) {
            $result = [];
            foreach ($schema['properties'] as $name => $prop) {
                $result[$name] = $this->extractObjectExample($prop, $spec);
            }

            return $result;
        }

        if (($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
            $item = $this->extractObjectExample($schema['items'], $spec);

            return $item !== null ? [$item] : [];
        }

        return $schema['example'] ?? $schema['default'] ?? null;
    }

    private function extractBaseUrl(array $spec): string
    {
        $servers = $spec['servers'] ?? [];
        if (! empty($servers)) {
            return rtrim($servers[0]['url'] ?? 'http://localhost', '/');
        }

        return 'http://localhost';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAuth(array $spec): ?array
    {
        $schemes = $spec['components']['securitySchemes'] ?? [];

        foreach ($schemes as $scheme) {
            if (($scheme['type'] ?? '') === 'http' && ($scheme['scheme'] ?? '') === 'bearer') {
                return [
                    'type' => 'bearer',
                    'bearer' => [
                        ['key' => 'token', 'value' => '{{apiToken}}', 'type' => 'string'],
                    ],
                ];
            }
        }

        return null;
    }
}
