<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Output;

class YamlWriter
{
    /**
     * Write an OpenAPI spec array to a YAML file.
     */
    public function write(array $spec, string $path): void
    {
        $this->validateOutputPath($path);

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $yaml = $this->toYaml($spec);
        file_put_contents($path, $yaml);
    }

    private function validateOutputPath(string $path): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw new \InvalidArgumentException("Invalid output file extension: {$extension}");
        }
    }

    /**
     * Convert spec to YAML string.
     * Uses a simple recursive converter (no ext-yaml dependency).
     */
    public function toYaml(array $spec, int $indent = 0): string
    {
        if (function_exists('yaml_emit')) {
            return yaml_emit($spec, YAML_UTF8_ENCODING);
        }

        return $this->arrayToYaml($spec, $indent);
    }

    private function arrayToYaml(mixed $data, int $indent = 0): string
    {
        if (! is_array($data)) {
            return $this->scalarToYaml($data);
        }

        if (empty($data)) {
            return $this->isSequential($data) ? '[]' : '{}';
        }

        $output = '';
        $prefix = str_repeat('  ', $indent);
        $isSequential = $this->isSequential($data);

        foreach ($data as $key => $value) {
            if ($isSequential) {
                if (is_array($value) && ! empty($value)) {
                    $output .= $prefix.'- '.ltrim($this->arrayToYaml($value, $indent + 1));
                } else {
                    $output .= $prefix.'- '.$this->scalarToYaml($value)."\n";
                }
            } else {
                if (is_array($value) && ! empty($value)) {
                    $output .= $prefix.$this->yamlKey($key).":\n";
                    $output .= $this->arrayToYaml($value, $indent + 1);
                } else {
                    $output .= $prefix.$this->yamlKey($key).': '.$this->scalarToYaml($value)."\n";
                }
            }
        }

        return $output;
    }

    private function scalarToYaml(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value) && empty($value)) {
            return $this->isSequential($value) ? '[]' : '{}';
        }
        if (is_string($value)) {
            // Quote strings that could be misinterpreted
            if (preg_match('/^[\d.]+$/', $value) || in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'])) {
                return "'".str_replace("'", "''", $value)."'";
            }
            if (str_contains($value, "\n") || str_contains($value, ':') || str_contains($value, '#') || str_contains($value, '{') || str_contains($value, '}') || str_contains($value, '[') || str_contains($value, ']')) {
                return '"'.addcslashes($value, '"\\').'"';
            }

            return $value;
        }

        return (string) $value;
    }

    private function yamlKey(string|int $key): string
    {
        $key = (string) $key;
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) && ! in_array(strtolower($key), ['true', 'false', 'null', 'yes', 'no'])) {
            return $key;
        }

        return "'".str_replace("'", "''", $key)."'";
    }

    private function isSequential(array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        return array_keys($data) === range(0, count($data) - 1);
    }
}
