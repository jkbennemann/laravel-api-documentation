<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionEnum;
use Throwable;

class EnumAnalyzer
{
    /**
     * Analyze PHP 8.1+ enum usage in validation rules and response types
     */
    public function analyzeEnumFromRule(object $rule): ?array
    {
        try {
            // Handle Enum validation rule instances
            if (class_exists('Illuminate\Validation\Rules\Enum') && $rule instanceof \Illuminate\Validation\Rules\Enum) {
                return $this->extractEnumFromValidationRule($rule);
            }

            // Handle direct enum classes
            if (enum_exists(get_class($rule))) {
                return $this->extractEnumFromInstance($rule);
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract enum information from Laravel's Enum validation rule
     */
    private function extractEnumFromValidationRule(\Illuminate\Validation\Rules\Enum $enumRule): ?array
    {
        try {
            $reflection = new ReflectionClass($enumRule);
            $typeProperty = $reflection->getProperty('type');
            $typeProperty->setAccessible(true);
            $enumClass = $typeProperty->getValue($enumRule);

            return $this->analyzeEnumClass($enumClass);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract enum information from enum instance
     */
    private function extractEnumFromInstance(object $enumInstance): array
    {
        $enumClass = get_class($enumInstance);

        return $this->analyzeEnumClass($enumClass) ?? [];
    }

    /**
     * Analyze enum class and extract OpenAPI schema information
     */
    public function analyzeEnumClass(string $enumClass): ?array
    {
        try {
            if (! enum_exists($enumClass)) {
                return null;
            }

            $enumReflection = new ReflectionEnum($enumClass);
            $cases = $enumReflection->getCases();

            if (empty($cases)) {
                return null;
            }

            $isBackedEnum = $enumReflection->isBacked();
            $values = [];
            $descriptions = [];
            $examples = [];

            foreach ($cases as $case) {
                if ($isBackedEnum) {
                    $value = $case->getValue()->value;
                    $values[] = $value;

                    // Try to get description from case docblock
                    $docComment = $case->getDocComment();
                    if ($docComment) {
                        $description = $this->extractDescriptionFromDocComment($docComment);
                        if ($description) {
                            $descriptions[$case->getName()] = $description;
                        }
                    }
                } else {
                    $values[] = $case->getName();
                }
            }

            // Determine the OpenAPI type based on enum values
            $firstValue = $values[0] ?? null;
            $type = 'string'; // Default type

            if ($isBackedEnum && $firstValue !== null) {
                $type = match (gettype($firstValue)) {
                    'integer' => 'integer',
                    'double' => 'number',
                    'boolean' => 'boolean',
                    default => 'string',
                };
            }

            $result = [
                'type' => $type,
                'enum' => $values,
                'description' => $this->generateEnumDescription($enumClass, $values, $descriptions),
                'example' => $firstValue,
            ];

            // Add enum case descriptions if available
            if (! empty($descriptions)) {
                $result['x-enum-descriptions'] = $descriptions;
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract enum class from AST node (for static analysis)
     */
    public function extractEnumFromAstNode($node): ?string
    {
        try {
            if ($node instanceof ClassConstFetch) {
                // Handle MyEnum::class patterns
                if ($node->name->toString() === 'class') {
                    $className = $node->class->toString();
                    if (enum_exists($className)) {
                        return $className;
                    }
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Analyze enum usage in source code files
     */
    public function findEnumsInFile(string $filePath): array
    {
        try {
            if (! file_exists($filePath)) {
                return [];
            }

            $parser = (new ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse(file_get_contents($filePath));

            $nodeFinder = new NodeFinder;
            $enumReferences = [];

            // Find enum class references
            $classConstFetches = $nodeFinder->findInstanceOf($ast, ClassConstFetch::class);

            foreach ($classConstFetches as $fetch) {
                if ($fetch->name->toString() === 'class') {
                    $className = $fetch->class->toString();
                    if (enum_exists($className)) {
                        $enumReferences[] = $className;
                    }
                }
            }

            // Find direct enum case references
            $enumCaseReferences = $nodeFinder->findInstanceOf($ast, ClassConstFetch::class);
            foreach ($enumCaseReferences as $fetch) {
                $className = $fetch->class->toString();
                if (enum_exists($className)) {
                    $enumReferences[] = $className;
                }
            }

            return array_unique($enumReferences);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Generate human-readable description for enum
     */
    private function generateEnumDescription(string $enumClass, array $values, array $descriptions): string
    {
        $shortName = (new ReflectionClass($enumClass))->getShortName();
        $valueList = implode(', ', array_map(fn ($v) => is_string($v) ? "'$v'" : $v, $values));

        $baseDescription = "Must be a valid {$shortName} value. Allowed values: {$valueList}.";

        if (! empty($descriptions)) {
            $baseDescription .= ' '.implode(' ', array_map(
                fn ($case, $desc) => "{$case}: {$desc}.",
                array_keys($descriptions),
                array_values($descriptions)
            ));
        }

        return $baseDescription;
    }

    /**
     * Extract description from docblock comment
     */
    private function extractDescriptionFromDocComment(string $docComment): ?string
    {
        // Remove /** and */ and extract the main description
        $comment = trim($docComment, "/* \t\n\r\0\x0B");
        $lines = explode("\n", $comment);

        $description = '';
        foreach ($lines as $line) {
            $line = trim($line, "* \t");
            if (empty($line) || str_starts_with($line, '@')) {
                break;
            }
            $description .= ($description ? ' ' : '').$line;
        }

        return $description ?: null;
    }

    /**
     * Check if a class name represents an enum
     */
    public function isEnumClass(string $className): bool
    {
        try {
            return enum_exists($className);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get enum cases as key-value pairs for documentation
     */
    public function getEnumCasesWithDescriptions(string $enumClass): array
    {
        try {
            if (! enum_exists($enumClass)) {
                return [];
            }

            $enumReflection = new ReflectionEnum($enumClass);
            $cases = $enumReflection->getCases();
            $result = [];

            foreach ($cases as $case) {
                $value = $enumReflection->isBacked() ? $case->getValue()->value : $case->getName();
                $description = null;

                // Try to extract description from case docblock
                $docComment = $case->getDocComment();
                if ($docComment) {
                    $description = $this->extractDescriptionFromDocComment($docComment);
                }

                $result[] = [
                    'name' => $case->getName(),
                    'value' => $value,
                    'description' => $description,
                ];
            }

            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Convert enum to OpenAPI schema with extended information
     */
    public function convertEnumToOpenApiSchema(string $enumClass, array $additionalProperties = []): array
    {
        $enumInfo = $this->analyzeEnumClass($enumClass);

        if (! $enumInfo) {
            return [
                'type' => 'string',
                'description' => 'Enum value',
            ];
        }

        // Merge with additional properties
        return array_merge($enumInfo, $additionalProperties);
    }
}
