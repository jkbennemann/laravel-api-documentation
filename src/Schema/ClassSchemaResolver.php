<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Schema;

use JkBennemann\LaravelApiDocumentation\Data\Reference;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class ClassSchemaResolver
{
    /** @var array<string, true> Classes currently being resolved (recursion guard) */
    private array $resolving = [];

    /** @var array<string, SchemaObject|null> Resolved class cache */
    private array $cache = [];

    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly TypeMapper $typeMapper,
    ) {}

    /**
     * Clear the resolved class cache. Must be called when SchemaRegistry is reset
     * (e.g. between multi-domain generation runs) so that nested $ref schemas
     * are re-registered in the fresh registry.
     */
    public function reset(): void
    {
        $this->resolving = [];
        $this->cache = [];
    }

    /**
     * Resolve a PHP class into a SchemaObject with typed properties.
     * Returns null for truly opaque/unresolvable classes.
     */
    public function resolve(string $className): ?SchemaObject
    {
        $className = ltrim($className, '\\');

        if ($className === '' || ! class_exists($className) && ! interface_exists($className) && ! enum_exists($className)) {
            return null;
        }

        // Return cached result
        if (array_key_exists($className, $this->cache)) {
            $cached = $this->cache[$className];
            if ($cached !== null) {
                return $this->wrapAsRef($className, $cached);
            }

            return null;
        }

        // Recursion guard — return a $ref placeholder
        if (isset($this->resolving[$className])) {
            $name = class_basename($className);

            return SchemaObject::ref(Reference::schema($name));
        }

        // DateTime classes
        if ($this->typeMapper->isDateTimeClass($className)) {
            return SchemaObject::string('date-time');
        }

        // Enums
        if ($this->typeMapper->isEnum($className)) {
            return $this->typeMapper->mapEnum($className);
        }

        // Collection — generic array
        if (str_contains($className, 'Collection')) {
            return new SchemaObject(type: 'array', items: SchemaObject::object());
        }

        // UUID/ULID
        $shortName = class_basename($className);
        if ($shortName === 'Uuid' || $shortName === 'Ulid') {
            return SchemaObject::string('uuid');
        }

        $this->resolving[$className] = true;

        try {
            $schema = $this->resolveClassProperties($className);

            if ($schema !== null) {
                $this->cache[$className] = $schema;

                return $this->wrapAsRef($className, $schema);
            }
        } finally {
            unset($this->resolving[$className]);
        }

        $this->cache[$className] = null;

        return null;
    }

    /**
     * Resolve a ReflectionType into a SchemaObject, using recursive class resolution.
     */
    public function resolveReflectionType(\ReflectionType $type): SchemaObject
    {
        if ($type instanceof \ReflectionNamedType) {
            if ($type->isBuiltin()) {
                $schema = $this->typeMapper->mapPhpType($type->getName());
            } else {
                $resolved = $this->resolve($type->getName());
                $schema = $resolved ?? $this->typeMapper->mapPhpType($type->getName());
            }

            if ($type->allowsNull()) {
                $schema = clone $schema;
                $schema->nullable = true;
            }

            return $schema;
        }

        if ($type instanceof \ReflectionUnionType) {
            $hasNull = false;
            $schemas = [];

            foreach ($type->getTypes() as $t) {
                if ($t instanceof \ReflectionNamedType && $t->getName() === 'null') {
                    $hasNull = true;

                    continue;
                }
                $schemas[] = $this->resolveReflectionType($t);
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
                fn (\ReflectionType $t) => $this->resolveReflectionType($t),
                $type->getTypes()
            );

            return new SchemaObject(allOf: $schemas);
        }

        return new SchemaObject;
    }

    private function resolveClassProperties(string $className): ?SchemaObject
    {
        try {
            $reflection = new \ReflectionClass($className);
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: Could not resolve class schema for {$className}: {$e->getMessage()}");
            }

            return null;
        }

        // Spatie Data subclass
        if (class_exists(\Spatie\LaravelData\Data::class) && is_subclass_of($className, \Spatie\LaravelData\Data::class)) {
            return $this->resolveSpatieData($reflection);
        }

        // Try constructor promoted properties first (readonly DTOs)
        $schema = $this->resolveFromConstructor($reflection);
        if ($schema !== null) {
            return $schema;
        }

        // Try public properties
        return $this->resolveFromPublicProperties($reflection);
    }

    private function resolveSpatieData(\ReflectionClass $reflection): ?SchemaObject
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return SchemaObject::object();
        }

        $properties = [];
        $required = [];
        $spatieInternal = ['_additional', '_wrap'];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (in_array($name, $spatieInternal, true)) {
                continue;
            }

            $type = $param->getType();
            if ($type !== null) {
                $propSchema = $this->resolveReflectionType($type);
            } else {
                $propSchema = SchemaObject::string();
            }

            // Handle Spatie Lazy types
            if ($type instanceof \ReflectionNamedType && str_contains($type->getName(), 'Lazy')) {
                $propSchema->nullable = true;
            }

            if ($param->isDefaultValueAvailable()) {
                try {
                    $propSchema->default = $param->getDefaultValue();
                } catch (\Throwable) {
                    // Skip
                }
            }

            $properties[$name] = $propSchema;

            if (! $param->isOptional() && ! $param->allowsNull()) {
                $required[] = $name;
            }
        }

        // Also check non-promoted public properties
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (isset($properties[$name]) || in_array($name, $spatieInternal, true)) {
                continue;
            }
            if ($prop->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $type = $prop->getType();
            $properties[$name] = $type !== null ? $this->resolveReflectionType($type) : SchemaObject::string();
        }

        return SchemaObject::object($properties, ! empty($required) ? $required : null);
    }

    private function resolveFromConstructor(\ReflectionClass $reflection): ?SchemaObject
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return null;
        }

        // Only if the constructor is on this class (not inherited)
        if ($constructor->getDeclaringClass()->getName() !== $reflection->getName()) {
            return null;
        }

        $properties = [];
        $required = [];
        $hasPromoted = false;

        foreach ($constructor->getParameters() as $param) {
            if (! $param->isPromoted()) {
                continue;
            }

            try {
                $propReflection = $reflection->getProperty($param->getName());
                if (! $propReflection->isPublic()) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $hasPromoted = true;
            $type = $param->getType();
            $propSchema = $type !== null ? $this->resolveReflectionType($type) : SchemaObject::string();
            $properties[$param->getName()] = $propSchema;

            if (! $param->isOptional() && ! ($type instanceof \ReflectionNamedType && $type->allowsNull())) {
                $required[] = $param->getName();
            }
        }

        if (! $hasPromoted) {
            return null;
        }

        // Also include non-promoted public properties
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (isset($properties[$prop->getName()])) {
                continue;
            }
            if ($prop->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $type = $prop->getType();
            $properties[$prop->getName()] = $type !== null ? $this->resolveReflectionType($type) : SchemaObject::string();
        }

        if (empty($properties)) {
            return null;
        }

        return SchemaObject::object($properties, ! empty($required) ? $required : null);
    }

    private function resolveFromPublicProperties(\ReflectionClass $reflection): ?SchemaObject
    {
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            if ($prop->isStatic()) {
                continue;
            }

            $type = $prop->getType();
            $properties[$prop->getName()] = $type !== null ? $this->resolveReflectionType($type) : SchemaObject::string();
        }

        if (empty($properties)) {
            return null;
        }

        return SchemaObject::object($properties);
    }

    private function wrapAsRef(string $className, SchemaObject $schema): SchemaObject
    {
        $name = class_basename($className);
        $refOrSchema = $this->registry->registerIfComplex($name, $schema);

        return $refOrSchema instanceof SchemaObject ? $refOrSchema : SchemaObject::ref($refOrSchema);
    }
}
