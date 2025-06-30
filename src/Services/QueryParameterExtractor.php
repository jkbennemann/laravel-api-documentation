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
    public function extractFromMethod(string $controller, string $method, array $pathParameters = []): array
    {
        if (! class_exists($controller)) {
            return [];
        }

        $reflection = new ReflectionMethod($controller, $method);
        $parameters = $reflection->getParameters();
        $docComment = $reflection->getDocComment();

        $queryParams = [];

        // Extract from method parameters
        foreach ($parameters as $parameter) {
            if ($this->isQueryParameter($parameter, $docComment, $pathParameters)) {
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
    protected function isQueryParameter(ReflectionParameter $parameter, ?string $docComment, array $pathParameters = []): bool
    {
        $typeName = $parameter->getType()?->getName() ?? '';
        $paramName = $parameter->getName();

        // If it's a request object, not a query param
        if (str_contains($typeName, 'Request')) {
            return false;
        }

        // If it's a model binding, not a query param
        if (class_exists($typeName) && method_exists($typeName, 'query')) {
            return false;
        }

        // If it's a path parameter from the route, not a query param
        if (in_array($paramName, $pathParameters)) {
            return false;
        }

        // Check docblock for @queryParam tag
        if ($docComment && preg_match('/@queryParam\s+'.$parameter->getName().'\b/', $docComment)) {
            return true;
        }

        // If parameter is primitive type and not documented as path param, assume query param
        return in_array($typeName, ['string', 'int', 'float', 'bool']) &&
               ! $this->isPathParameter($parameter->getName(), $docComment);
    }

    /**
     * Check if parameter is documented as path parameter
     */
    protected function isPathParameter(string $name, ?string $docComment): bool
    {
        if (! $docComment) {
            return false;
        }

        return (bool) preg_match('/@pathParam\s+'.$name.'\b/', $docComment);
    }

    /**
     * Build schema for parameter
     */
    protected function buildParameterSchema(ReflectionParameter $parameter, ?string $docComment): array
    {
        // Rule: Keep the code modular and easy to understand
        $type = $parameter->getType()?->getName() ?? 'string';
        $paramName = $parameter->getName();
        $schema = [
            'description' => $this->getParameterDescription($paramName, $docComment),
            'required' => ! $parameter->isOptional(),
            'type' => $this->mapPhpTypeToOpenApi($type),
            'format' => $this->getFormatForType($type, $paramName),
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
        if (! $docComment) {
            return '';
        }

        if (preg_match('/@queryParam\s+'.$name.'\s+(.+?)(?=\s+@|\s*\*\/)/s', $docComment, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * Extract parameter example from docblock
     */
    protected function getParameterExample(string $name, ?string $docComment): ?array
    {
        if (! $docComment) {
            return null;
        }

        if (preg_match('/@example\s+'.$name.'\s+(.+?)(?=\s+@|\s*\*\/)/s', $docComment, $matches)) {
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
        // Rule: Write concise, technical PHP code with accurate examples
        if (! $docComment) {
            return;
        }

        // Rule: Write concise, technical PHP code with accurate examples
        // Match @queryParam annotations with improved regex to capture type and description
        // Format examples that we want to support:
        // @queryParam page int Page number for pagination. Example: 1
        // @queryParam filter string Filter results by category
        // @queryParam {int} page Page number for pagination
        // @queryParam page The page number

        // Enhanced regex pattern to better handle curly brace format
        preg_match_all('/@queryParam\s+(\w+)(?:\s+(?:{([\w|\\<>]+)}|([\w|\\<>]+)))?(?:\s+(.+?))?(?=\s+@|\s*\*\/|$)/s', $docComment, $matches, PREG_SET_ORDER);

        // Also try to match the curly brace format when it comes before the parameter name
        preg_match_all('/@queryParam\s+{([\w|\\<>]+)}\s+(\w+)(?:\s+(.+?))?(?=\s+@|\s*\*\/|$)/s', $docComment, $curlyMatches, PREG_SET_ORDER);

        // Convert curly matches to standard format and merge with regular matches
        foreach ($curlyMatches as $match) {
            $matches[] = [
                0 => $match[0],
                1 => $match[2], // Parameter name
                2 => $match[1], // Type in curly braces
                3 => '',
                4 => $match[3] ?? '', // Description
            ];
        }

        foreach ($matches as $match) {
            $name = $match[1];

            // Extract type from either format: {type} or type description
            $type = ! empty($match[2]) ? $match[2] : (! empty($match[3]) ? $match[3] : 'string');

            // Handle union types (e.g., string|int) by using the first type
            if (strpos($type, '|') !== false) {
                $typeOptions = explode('|', $type);
                $type = trim($typeOptions[0]);
            }

            // Get description, which is either the 4th capture group or empty
            $description = $match[4] ?? '';

            // Skip if already processed from method parameters
            if (isset($queryParams[$name])) {
                continue;
            }

            // Rule: Prefer iteration and modularization over code duplication
            // We already extracted type information in the enhanced regex above
            // Now map PHP type to OpenAPI type
            $openApiType = $this->mapPhpTypeToOpenApi($type);

            // Determine if parameter is required
            // We'll consider it required by default unless 'optional' is mentioned
            $isRequired = true;
            if (str_contains(strtolower($description), 'optional')) {
                $isRequired = false;
            }

            // Extract example value if provided
            $example = null;
            if (preg_match('/Example:\s*(.+?)(?=\s+@|\s*$)/i', $description, $exampleMatch)) {
                $example = trim($exampleMatch[1]);
                // Remove the example part from the description
                $description = trim(str_replace($exampleMatch[0], '', $description));
            }

            // Clean up any extra whitespace and asterisks from docblock formatting
            $description = preg_replace('/\s*\*\s*/', ' ', $description);
            $description = trim($description);

            // Rule: Document API interactions and data flows
            // Create the parameter schema with enhanced type and format detection
            $queryParams[$name] = [
                'description' => $description,
                'required' => $isRequired,
                'type' => $openApiType, // Use already mapped type
                'format' => $this->getFormatForType($type, $name),
            ];

            // Add example from parsed inline example or from separate @example tag
            if ($example !== null) {
                // We have an inline example from the description
                $queryParams[$name]['example'] = [
                    'value' => $example,
                    'type' => $this->detectExampleType($example),
                    'format' => $this->detectExampleFormat($example),
                ];
            } else {
                // Try to find a separate @example tag
                $exampleFromTag = $this->getParameterExample($name, $docComment);
                if ($exampleFromTag) {
                    $queryParams[$name]['example'] = $exampleFromTag;
                }
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
        if (is_numeric($value) && ! str_contains($value, '.')) {
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
     *
     * Rule: Keep the code clean and readable
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

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return 'uri';
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return 'ipv4';
        }

        return null;
    }

    /**
     * Map PHP types to OpenAPI types
     *
     * Rule: Stick to PHP best practices
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
     * Get format for specific types and parameter names
     *
     * Rule: Keep the code clean and readable
     *
     * @param  string  $type  The parameter type
     * @param  string|null  $paramName  The parameter name (optional)
     * @return string|null The OpenAPI format or null if no format applies
     */
    protected function getFormatForType(string $type, ?string $paramName = null): ?string
    {
        // First check type-based formats
        $typeFormat = match ($type) {
            'int', 'integer' => 'int32',
            'float', 'double' => 'float',
            'DateTime', '\\DateTime' => 'date-time',
            'DateTimeImmutable', '\\DateTimeImmutable' => 'date-time',
            default => null,
        };

        // If no parameter name provided, return type-based format
        if (! $paramName) {
            return $typeFormat;
        }

        // Handle specific types with name-based formats
        if ($type === 'int' || $type === 'integer') {
            if (str_contains(strtolower($paramName), 'id')) {
                return 'int64';
            }

            return 'int32';
        }

        // For string types, infer format from parameter name
        if ($type === 'string') {
            $lcName = strtolower($paramName);

            // Handle date and time formats
            if (str_contains($lcName, 'date') ||
                str_contains($lcName, 'time') ||
                preg_match('/(created|updated)_?at/i', $paramName)) {
                return 'date-time';
            }

            if (str_contains($lcName, 'email')) {
                return 'email';
            }

            if (str_contains($lcName, 'password')) {
                return 'password';
            }

            if (str_contains($lcName, 'url') || str_contains($lcName, 'uri')) {
                return 'uri';
            }

            if (str_contains($lcName, 'uuid')) {
                return 'uuid';
            }

            if (str_contains($lcName, 'ip')) {
                return 'ipv4';
            }
        }

        return $typeFormat;
    }
}
