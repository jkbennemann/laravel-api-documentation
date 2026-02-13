<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\Reference;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;

class CodeSamplePlugin implements OperationTransformer, Plugin
{
    /** @var string[] */
    private array $languages;

    private ?string $baseUrl;

    /**
     * @param  string[]|null  $languages  Languages to generate (null = all)
     */
    public function __construct(?array $languages = null, ?string $baseUrl = null, private ?SchemaRegistry $schemaRegistry = null)
    {
        $this->languages = $languages ?? ['bash', 'javascript', 'php', 'python'];
        $this->baseUrl = $baseUrl;
    }

    public function name(): string
    {
        return 'code-samples';
    }

    public function boot(PluginRegistry $registry): void
    {
        $registry->addOperationTransformer($this, 30);
    }

    public function priority(): int
    {
        return 30;
    }

    public function transform(array $operation, AnalysisContext $ctx): array
    {
        $method = strtoupper($ctx->route->httpMethod());
        $path = '/'.ltrim($ctx->route->uri, '/');
        $baseUrl = $this->baseUrl ?? '{baseUrl}';
        $url = rtrim($baseUrl, '/').$path;

        $hasAuth = ! empty($operation['security']);
        $requestBody = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        $contentType = $requestBody !== null ? 'application/json' : null;

        // Build example body from schema properties
        $exampleBody = null;
        if ($requestBody !== null) {
            $exampleBody = $this->buildExampleBody($requestBody);
        }

        $samples = [];

        foreach ($this->languages as $lang) {
            $source = match ($lang) {
                'bash' => $this->generateCurl($method, $url, $exampleBody, $contentType, $hasAuth),
                'javascript' => $this->generateJavaScript($method, $url, $exampleBody, $contentType, $hasAuth),
                'php' => $this->generatePhp($method, $url, $exampleBody, $contentType, $hasAuth),
                'python' => $this->generatePython($method, $url, $exampleBody, $contentType, $hasAuth),
                default => null,
            };

            if ($source !== null) {
                $samples[] = [
                    'lang' => match ($lang) {
                        'bash' => 'Shell',
                        'javascript' => 'JavaScript',
                        'php' => 'PHP',
                        'python' => 'Python',
                        default => ucfirst($lang),
                    },
                    'label' => match ($lang) {
                        'bash' => 'cURL',
                        'javascript' => 'JavaScript',
                        'php' => 'PHP',
                        'python' => 'Python',
                        default => ucfirst($lang),
                    },
                    'source' => $source,
                ];
            }
        }

        if (! empty($samples)) {
            $operation['x-codeSamples'] = $samples;
        }

        return $operation;
    }

    private function generateCurl(string $method, string $url, ?string $body, ?string $contentType, bool $hasAuth): string
    {
        // Replace path parameters with example values
        $url = $this->replacePathParams($url);

        $parts = ["curl -X {$method}"];
        $parts[] = "  '{$url}'";

        if ($hasAuth) {
            $parts[] = "  -H 'Authorization: Bearer YOUR_API_TOKEN'";
        }

        if ($contentType !== null) {
            $parts[] = "  -H 'Content-Type: {$contentType}'";
        }

        if ($body !== null) {
            $parts[] = "  -d '{$body}'";
        }

        return implode(" \\\n", $parts);
    }

    private function generateJavaScript(string $method, string $url, ?string $body, ?string $contentType, bool $hasAuth): string
    {
        $url = $this->replacePathParams($url);

        $lines = ['const response = await fetch(\''.$url.'\', {'];
        $lines[] = "  method: '{$method}',";

        $headers = [];
        if ($hasAuth) {
            $headers[] = "    'Authorization': 'Bearer YOUR_API_TOKEN'";
        }
        if ($contentType !== null) {
            $headers[] = "    'Content-Type': '{$contentType}'";
        }

        if (! empty($headers)) {
            $lines[] = '  headers: {';
            $lines[] = implode(",\n", $headers);
            $lines[] = '  },';
        }

        if ($body !== null) {
            $lines[] = "  body: JSON.stringify({$body}),";
        }

        $lines[] = '});';
        $lines[] = '';
        $lines[] = 'const data = await response.json();';

        return implode("\n", $lines);
    }

    private function generatePhp(string $method, string $url, ?string $body, ?string $contentType, bool $hasAuth): string
    {
        $url = $this->replacePathParams($url);

        $lines = ['$client = new \\GuzzleHttp\\Client();'];
        $lines[] = '';

        $options = [];
        if ($hasAuth) {
            $options[] = "    'headers' => [";
            $options[] = "        'Authorization' => 'Bearer YOUR_API_TOKEN',";
            if ($contentType !== null) {
                $options[] = "        'Content-Type' => '{$contentType}',";
            }
            $options[] = '    ],';
        } elseif ($contentType !== null) {
            $options[] = "    'headers' => [";
            $options[] = "        'Content-Type' => '{$contentType}',";
            $options[] = '    ],';
        }

        if ($body !== null) {
            $options[] = "    'json' => {$this->phpArrayFromJson($body)},";
        }

        $lines[] = "\$response = \$client->request('{$method}', '{$url}'";

        if (! empty($options)) {
            $lines[count($lines) - 1] .= ', [';
            foreach ($options as $opt) {
                $lines[] = $opt;
            }
            $lines[] = ']);';
        } else {
            $lines[count($lines) - 1] .= ');';
        }

        $lines[] = '';
        $lines[] = '$body = json_decode($response->getBody(), true);';

        return implode("\n", $lines);
    }

    private function generatePython(string $method, string $url, ?string $body, ?string $contentType, bool $hasAuth): string
    {
        $url = $this->replacePathParams($url);
        $methodLower = strtolower($method);

        $lines = ['import requests'];
        $lines[] = '';

        $headerParts = [];
        if ($hasAuth) {
            $headerParts[] = "    'Authorization': 'Bearer YOUR_API_TOKEN'";
        }
        if ($contentType !== null) {
            $headerParts[] = "    'Content-Type': '{$contentType}'";
        }

        if (! empty($headerParts)) {
            $lines[] = 'headers = {';
            $lines[] = implode(",\n", $headerParts);
            $lines[] = '}';
            $lines[] = '';
        }

        $args = ["'{$url}'"];

        if (! empty($headerParts)) {
            $args[] = 'headers=headers';
        }

        if ($body !== null) {
            $lines[] = "payload = {$body}";
            $lines[] = '';
            $args[] = 'json=payload';
        }

        $argsStr = implode(', ', $args);
        $lines[] = "response = requests.{$methodLower}({$argsStr})";
        $lines[] = 'data = response.json()';

        return implode("\n", $lines);
    }

    /**
     * Build an example JSON body from schema properties.
     */
    private function buildExampleBody(array $schema): ?string
    {
        $example = $this->extractExample($schema);

        if ($example === null || $example === []) {
            return null;
        }

        return json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function extractExample(array $schema): mixed
    {
        // Resolve $ref schemas
        if (isset($schema['$ref']) && $this->schemaRegistry !== null) {
            $name = str_replace('#/components/schemas/', '', $schema['$ref']);
            $resolved = $this->schemaRegistry->resolve(Reference::schema($name));
            if ($resolved !== null) {
                return $this->extractExample($resolved->jsonSerialize());
            }

            return null;
        }

        // If the schema has a direct example, use it
        if (isset($schema['example'])) {
            return $schema['example'];
        }

        // For objects, build from properties
        if (($schema['type'] ?? null) === 'object' && isset($schema['properties'])) {
            $result = [];
            foreach ($schema['properties'] as $name => $prop) {
                $result[$name] = $this->extractExample($prop);
            }

            return $result;
        }

        // For arrays, build from items
        if (($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
            $item = $this->extractExample($schema['items']);

            return $item !== null ? [$item] : [];
        }

        // Type-based fallback
        return match ($schema['type'] ?? null) {
            'string' => 'string',
            'integer' => 1,
            'number' => 0.0,
            'boolean' => true,
            default => null,
        };
    }

    private function replacePathParams(string $url): string
    {
        return preg_replace('/\{(\w+)\??\}/', ':$1', $url);
    }

    private function phpArrayFromJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if ($decoded === null) {
            return '[]';
        }

        return $this->arrayToPhpCode($decoded, 2);
    }

    private function arrayToPhpCode(mixed $data, int $indent): string
    {
        if (! is_array($data)) {
            if (is_string($data)) {
                return "'".addslashes($data)."'";
            }
            if (is_bool($data)) {
                return $data ? 'true' : 'false';
            }
            if (is_null($data)) {
                return 'null';
            }

            return (string) $data;
        }

        $isAssoc = array_keys($data) !== range(0, count($data) - 1);
        $pad = str_repeat('    ', $indent);
        $padInner = str_repeat('    ', $indent + 1);

        $items = [];
        foreach ($data as $key => $value) {
            $val = $this->arrayToPhpCode($value, $indent + 1);
            if ($isAssoc) {
                $items[] = "{$padInner}'".addslashes((string) $key)."' => {$val}";
            } else {
                $items[] = "{$padInner}{$val}";
            }
        }

        if (empty($items)) {
            return '[]';
        }

        return "[\n".implode(",\n", $items).",\n{$pad}]";
    }
}
