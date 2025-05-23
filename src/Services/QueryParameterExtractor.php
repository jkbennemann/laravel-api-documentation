<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use ReflectionMethod;
use ReflectionParameter;

class QueryParameterExtractor
{
    /**
     * Extract query parameters from method signature and docblock
     */
    public function extractFromMethod(string $controller, string $method): array
    {
        if (!class_exists($controller)) {
            return [];
        }

        $reflection = new ReflectionMethod($controller, $method);
        $parameters = $reflection->getParameters();
        $docComment = $reflection->getDocComment();

        $queryParams = [];

        // Extract from method parameters
        foreach ($parameters as $parameter) {
            if ($this->isQueryParameter($parameter, $docComment)) {
                $queryParams[$parameter->getName()] = $this->buildParameterSchema($parameter, $docComment);
            }
        }

        // Extract additional params from docblock
        $this->extractFromDocBlock($docComment, $queryParams);

        return $queryParams;
    }

    /**
     * Determine if a parameter should be treated as a query parameter
     */
    protected function isQueryParameter(ReflectionParameter $parameter, ?string $docComment): bool
    {
        $typeName = $parameter->getType()?->getName() ?? '';

        // If it's a request object, not a query param
        if (str_contains($typeName, 'Request')) {
            return false;
        }

        // If it's a model binding, not a query param
        if (class_exists($typeName) && method_exists($typeName, 'query')) {
            return false;
        }

        // Check docblock for @queryParam tag
        if ($docComment && preg_match('/@queryParam\s+' . $parameter->getName() . '\b/', $docComment)) {
            return true;
        }

        // If parameter is primitive type and not documented as path param, assume query param
        return in_array($typeName, ['string', 'int', 'float', 'bool']) &&
               !$this->isPathParameter($parameter->getName(), $docComment);
    }

    /**
     * Check if parameter is documented as path parameter
     */
    protected function isPathParameter(string $name, ?string $docComment): bool
    {
        if (!$docComment) {
            return false;
        }

        return (bool) preg_match('/@pathParam\s+' . $name . '\b/', $docComment);
    }

    /**
     * Build schema for parameter
     */
    protected function buildParameterSchema(ReflectionParameter $parameter, ?string $docComment): array
    {
        $type = $parameter->getType()?->getName() ?? 'string';
        $schema = [
            'description' => $this->getParameterDescription($parameter->getName(), $docComment),
            'required' => !$parameter->isOptional(),
            'type' => $this->mapPhpTypeToOpenApi($type),
            'format' => $this->getFormatForType($type),
        ];

        // Add example if available in docblock
        $example = $this->getParameterExample($parameter->getName(), $docComment);
        if ($example) {
            $schema['example'] = $example;
        }

        return $schema;
    }

    /**
     * Extract parameter description from docblock
     */
    protected function getParameterDescription(string $name, ?string $docComment): string
    {
        if (!$docComment) {
            return '';
        }

        if (preg_match('/@queryParam\s+' . $name . '\s+(.+?)(?=\s+@|\s*\*\/)/s', $docComment, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * Extract parameter example from docblock
     */
    protected function getParameterExample(string $name, ?string $docComment): ?array
    {
        if (!$docComment) {
            return null;
        }

        if (preg_match('/@example\s+' . $name . '\s+(.+?)(?=\s+@|\s*\*\/)/s', $docComment, $matches)) {
            $value = trim($matches[1]);
            return [
                'value' => $value,
                'type' => $this->detectExampleType($value),
                'format' => $this->detectExampleFormat($value),
            ];
        }

        return null;
    }

    /**
     * Extract additional parameters from docblock
     */
    protected function extractFromDocBlock(?string $docComment, array &$queryParams): void
    {
        if (!$docComment) {
            return;
        }

        preg_match_all('/@queryParam\s+(\w+)(?:\s+(.+?))?(?=\s+@|\s*\*\/)/s', $docComment, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = $match[1];

            // Skip if already processed from method parameters
            if (isset($queryParams[$name])) {
                continue;
            }

            $description = $match[2] ?? '';

            // Check if description contains type info
            $type = 'string';
            $isRequired = true;

            if (preg_match('/\{(.+?)\}/', $description, $typeMatch)) {
                $typeInfo = $typeMatch[1];
                $type = $this->extractTypeFromInfo($typeInfo);
                $isRequired = !str_contains($typeInfo, 'optional');
                $description = trim(str_replace($typeMatch[0], '', $description));
            }

            $queryParams[$name] = [
                'description' => $description,
                'required' => $isRequired,
                'type' => $this->mapPhpTypeToOpenApi($type),
                'format' => $this->getFormatForType($type),
            ];

            // Add example if available
            $example = $this->getParameterExample($name, $docComment);
            if ($example) {
                $queryParams[$name]['example'] = $example;
            }
        }
    }

    /**
     * Extract type from type info string
     */
    protected function extractTypeFromInfo(string $typeInfo): string
    {
        $typeInfo = strtolower($typeInfo);

        if (str_contains($typeInfo, 'int')) {
            return 'integer';
        }

        if (str_contains($typeInfo, 'bool')) {
            return 'boolean';
        }

        if (str_contains($typeInfo, 'float') || str_contains($typeInfo, 'double')) {
            return 'number';
        }

        if (str_contains($typeInfo, 'array')) {
            return 'array';
        }

        return 'string';
    }

    /**
     * Detect example type
     */
    protected function detectExampleType(string $value): string
    {
        if (is_numeric($value) && !str_contains($value, '.')) {
            return 'integer';
        }

        if (is_numeric($value) && str_contains($value, '.')) {
            return 'number';
        }

        if (in_array(strtolower($value), ['true', 'false'])) {
            return 'boolean';
        }

        return 'string';
    }

    /**
     * Detect example format
     */
    protected function detectExampleFormat(string $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return 'date';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[\+-]\d{2}:\d{2})?$/', $value)) {
            return 'date-time';
        }

        return null;
    }

    /**
     * Map PHP types to OpenAPI types
     */
    protected function mapPhpTypeToOpenApi(string $type): string
    {
        return match ($type) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            'object', 'stdClass' => 'object',
            default => 'string',
        };
    }

    /**
     * Get format for specific types
     */
    protected function getFormatForType(string $type): ?string
    {
        return match ($type) {
            'float', 'double' => 'float',
            'DateTime', '\DateTime' => 'date-time',
            'DateTimeImmutable', '\DateTimeImmutable' => 'date-time',
            default => null,
        };
    }
}
