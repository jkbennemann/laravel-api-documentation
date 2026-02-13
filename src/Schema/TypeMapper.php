<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Schema;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class TypeMapper
{
    private ?ClassSchemaResolver $classResolver = null;

    public function setClassResolver(ClassSchemaResolver $resolver): void
    {
        $this->classResolver = $resolver;
    }

    /**
     * Map a PHP type string to an OpenAPI SchemaObject.
     */
    public function mapPhpType(string $type): SchemaObject
    {
        $nullable = false;

        // Handle nullable type prefix
        if (str_starts_with($type, '?')) {
            $nullable = true;
            $type = substr($type, 1);
        }

        // Handle union types containing null
        if (str_contains($type, '|')) {
            $parts = explode('|', $type);
            $parts = array_filter($parts, fn ($p) => strtolower(trim($p)) !== 'null');
            if (count($parts) < count(explode('|', $type))) {
                $nullable = true;
            }
            if (count($parts) === 1) {
                $type = trim(reset($parts));
            } else {
                // Union type → oneOf
                $schemas = array_map(fn ($p) => $this->mapPhpType(trim($p)), $parts);

                return new SchemaObject(oneOf: $schemas, nullable: $nullable);
            }
        }

        $schema = match (strtolower($type)) {
            'string' => SchemaObject::string(),
            'int', 'integer' => SchemaObject::integer(),
            'float', 'double' => SchemaObject::number('double'),
            'bool', 'boolean' => SchemaObject::boolean(),
            'array' => new SchemaObject(type: 'array', items: new SchemaObject(type: 'string')),
            'object', 'stdclass' => SchemaObject::object(),
            'mixed' => new SchemaObject,
            'void', 'never' => new SchemaObject(type: 'null'),
            default => $this->mapClassName($type),
        };

        if ($nullable) {
            $schema->nullable = true;
        }

        return $schema;
    }

    /**
     * Map a class name to a schema, handling common Laravel/PHP types.
     */
    public function mapClassName(string $className): SchemaObject
    {
        // Normalize class name
        $className = ltrim($className, '\\');
        $shortName = class_basename($className);

        return match (true) {
            // Carbon / DateTime
            $this->isDateTimeClass($className) => SchemaObject::string('date-time'),

            // Laravel Collection
            str_contains($className, 'Collection') => new SchemaObject(
                type: 'array',
                items: SchemaObject::object()
            ),

            // UUIDs
            $shortName === 'Uuid' || $shortName === 'Ulid' => SchemaObject::string('uuid'),

            // Enums - try to extract values
            $this->isEnum($className) => $this->mapEnum($className),

            // Default: try recursive class resolution, fall back to bare object
            default => $this->classResolver?->resolve($className) ?? SchemaObject::object(),
        };
    }

    public function mapReflectionType(\ReflectionType $type): SchemaObject
    {
        if ($type instanceof \ReflectionNamedType) {
            $schema = $this->mapPhpType($type->getName());
            if ($type->allowsNull()) {
                $schema->nullable = true;
            }

            return $schema;
        }

        if ($type instanceof \ReflectionUnionType) {
            $types = $type->getTypes();
            $hasNull = false;
            $schemas = [];

            foreach ($types as $t) {
                if ($t instanceof \ReflectionNamedType && $t->getName() === 'null') {
                    $hasNull = true;

                    continue;
                }
                $schemas[] = $this->mapReflectionType($t);
            }

            if (count($schemas) === 1) {
                $schema = clone $schemas[0];
                $schema->nullable = $hasNull;

                return $schema;
            }

            return new SchemaObject(oneOf: $schemas, nullable: $hasNull);
        }

        if ($type instanceof \ReflectionIntersectionType) {
            $schemas = array_map(
                fn (\ReflectionType $t) => $this->mapReflectionType($t),
                $type->getTypes()
            );

            return new SchemaObject(allOf: $schemas);
        }

        return new SchemaObject;
    }

    public function isDateTimeClass(string $className): bool
    {
        $dateClasses = [
            'Carbon\Carbon',
            'Carbon\CarbonImmutable',
            'Illuminate\Support\Carbon',
            'DateTime',
            'DateTimeImmutable',
            'DateTimeInterface',
        ];

        return in_array($className, $dateClasses, true)
            || is_subclass_of($className, \DateTimeInterface::class);
    }

    public function isEnum(string $className): bool
    {
        if (! class_exists($className) && ! interface_exists($className) && ! enum_exists($className)) {
            return false;
        }

        return enum_exists($className);
    }

    public function mapEnum(string $className): SchemaObject
    {
        try {
            $reflection = new \ReflectionEnum($className);
            $cases = $reflection->getCases();

            $values = [];
            $backingType = $reflection->getBackingType();

            foreach ($cases as $case) {
                if ($case instanceof \ReflectionEnumBackedCase) {
                    $values[] = $case->getBackingValue();
                } else {
                    $values[] = $case->getName();
                }
            }

            if ($backingType instanceof \ReflectionNamedType) {
                $type = match ($backingType->getName()) {
                    'int' => 'integer',
                    'string' => 'string',
                    default => 'string',
                };
            } else {
                $type = 'string';
            }

            return new SchemaObject(type: $type, enum: $values);
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Could not resolve enum schema for {$className}: {$e->getMessage()}");
            }

            return SchemaObject::string();
        }
    }
}
