<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Output;

class TypeScriptGenerator
{
    /**
     * Generate TypeScript interface definitions from an OpenAPI spec.
     *
     * @param  array<string, mixed>  $spec
     */
    public function generate(array $spec): string
    {
        $lines = [
            '// Auto-generated TypeScript types from OpenAPI spec',
            '// Generated at: '.date('Y-m-d H:i:s'),
            '',
        ];

        // Generate interfaces from component schemas
        foreach ($spec['components']['schemas'] ?? [] as $name => $schema) {
            $interface = $this->generateInterface($name, $schema, $spec);
            if ($interface !== null) {
                $lines[] = $interface;
                $lines[] = '';
            }
        }

        // Generate request/response types from paths
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $operationId = $operation['operationId'] ?? null;
                if ($operationId === null) {
                    continue;
                }

                $typeName = $this->operationIdToTypeName($operationId);

                // Request body type
                $requestSchema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
                if ($requestSchema !== null && ! isset($requestSchema['$ref'])) {
                    $interface = $this->generateInterface($typeName.'Request', $requestSchema, $spec);
                    if ($interface !== null) {
                        $lines[] = $interface;
                        $lines[] = '';
                    }
                }

                // Success response type
                foreach ($operation['responses'] ?? [] as $status => $response) {
                    $statusInt = (int) $status;
                    if ($statusInt >= 200 && $statusInt < 300) {
                        $responseSchema = $response['content']['application/json']['schema'] ?? null;
                        if ($responseSchema !== null && ! isset($responseSchema['$ref'])) {
                            $interface = $this->generateInterface($typeName.'Response', $responseSchema, $spec);
                            if ($interface !== null) {
                                $lines[] = $interface;
                                $lines[] = '';
                            }
                        }
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Write TypeScript definitions to a file.
     */
    public function write(array $spec, string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->generate($spec));
    }

    private function generateInterface(string $name, array $schema, array $spec): ?string
    {
        // Resolve $ref
        if (isset($schema['$ref'])) {
            return null;
        }

        $type = $schema['type'] ?? null;

        if ($type === 'object' && isset($schema['properties'])) {
            return $this->generateObjectInterface($name, $schema, $spec);
        }

        if ($type === 'array' && isset($schema['items'])) {
            $itemType = $this->schemaToTypeScript($schema['items'], $spec);

            return "export type {$name} = {$itemType}[];";
        }

        // Enum type
        if (isset($schema['enum'])) {
            return $this->generateEnumType($name, $schema);
        }

        return null;
    }

    private function generateObjectInterface(string $name, array $schema, array $spec): string
    {
        $required = array_flip($schema['required'] ?? []);
        $lines = ["export interface {$name} {"];

        foreach ($schema['properties'] ?? [] as $propName => $propSchema) {
            $optional = isset($required[$propName]) ? '' : '?';
            $tsType = $this->schemaToTypeScript($propSchema, $spec);
            $comment = $this->buildPropertyComment($propSchema);

            if ($comment !== '') {
                $lines[] = "  {$comment}";
            }

            $lines[] = "  {$propName}{$optional}: {$tsType};";
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function generateEnumType(string $name, array $schema): string
    {
        $values = array_map(function ($v) {
            if (is_string($v)) {
                return "'".addslashes($v)."'";
            }

            return (string) $v;
        }, $schema['enum']);

        return "export type {$name} = ".implode(' | ', $values).';';
    }

    private function schemaToTypeScript(array $schema, array $spec): string
    {
        // $ref
        if (isset($schema['$ref'])) {
            $refName = str_replace('#/components/schemas/', '', $schema['$ref']);

            return $refName;
        }

        $type = $schema['type'] ?? 'any';

        // Handle nullable (OpenAPI 3.1 style: type array with 'null')
        $nullable = false;
        if (is_array($type)) {
            $nullable = in_array('null', $type, true);
            $type = array_values(array_filter($type, fn ($t) => $t !== 'null'))[0] ?? 'any';
        }

        // Enum
        if (isset($schema['enum'])) {
            $values = array_map(function ($v) {
                if (is_string($v)) {
                    return "'".addslashes($v)."'";
                }

                return (string) $v;
            }, $schema['enum']);

            $tsType = implode(' | ', $values);

            return $nullable ? "({$tsType}) | null" : $tsType;
        }

        $tsType = match ($type) {
            'string' => 'string',
            'integer', 'number' => 'number',
            'boolean' => 'boolean',
            'array' => $this->arrayToTypeScript($schema, $spec),
            'object' => $this->inlineObjectToTypeScript($schema, $spec),
            default => 'any',
        };

        return $nullable ? "{$tsType} | null" : $tsType;
    }

    private function arrayToTypeScript(array $schema, array $spec): string
    {
        if (isset($schema['items'])) {
            $itemType = $this->schemaToTypeScript($schema['items'], $spec);

            return "{$itemType}[]";
        }

        return 'any[]';
    }

    private function inlineObjectToTypeScript(array $schema, array $spec): string
    {
        if (! isset($schema['properties'])) {
            return 'Record<string, any>';
        }

        $required = array_flip($schema['required'] ?? []);
        $props = [];

        foreach ($schema['properties'] as $name => $propSchema) {
            $optional = isset($required[$name]) ? '' : '?';
            $tsType = $this->schemaToTypeScript($propSchema, $spec);
            $props[] = "{$name}{$optional}: {$tsType}";
        }

        return '{ '.implode('; ', $props).' }';
    }

    private function buildPropertyComment(array $schema): string
    {
        $parts = [];

        if (isset($schema['description'])) {
            $parts[] = $schema['description'];
        }

        if (isset($schema['format'])) {
            $parts[] = '@format '.$schema['format'];
        }

        if (isset($schema['example'])) {
            $example = is_string($schema['example']) ? "\"{$schema['example']}\"" : json_encode($schema['example']);
            $parts[] = '@example '.$example;
        }

        if (empty($parts)) {
            return '';
        }

        return '/** '.implode(' — ', $parts).' */';
    }

    private function operationIdToTypeName(string $operationId): string
    {
        // Convert 'get.api.users' to 'GetApiUsers'
        return str_replace(' ', '', ucwords(str_replace(['.', '-', '_'], ' ', $operationId)));
    }
}
