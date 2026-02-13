<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Response;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Schema\ClassSchemaResolver;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;

class SpatieDataResponseAnalyzer
{
    private TypeMapper $typeMapper;

    /** @var array<string, SchemaObject|null> */
    private array $analyzing = [];

    private ?ClassSchemaResolver $classResolver = null;

    public function __construct(
        private readonly SchemaRegistry $registry,
    ) {
        $this->typeMapper = new TypeMapper;
    }

    public function setClassResolver(ClassSchemaResolver $resolver): void
    {
        $this->classResolver = $resolver;
    }

    public function analyze(string $dataClass): ?SchemaObject
    {
        if (! class_exists(\Spatie\LaravelData\Data::class)) {
            return null;
        }

        if (! is_subclass_of($dataClass, \Spatie\LaravelData\Data::class)) {
            return null;
        }

        // Prevent recursion
        if (isset($this->analyzing[$dataClass])) {
            return $this->analyzing[$dataClass];
        }
        $this->analyzing[$dataClass] = null;

        try {
            $schema = $this->extractFromReflection($dataClass);

            if ($schema !== null) {
                $name = class_basename($dataClass);
                $this->analyzing[$dataClass] = $schema;
                $refOrSchema = $this->registry->registerIfComplex($name, $schema);

                return $refOrSchema instanceof SchemaObject ? $refOrSchema : SchemaObject::ref($refOrSchema);
            }
        } finally {
            unset($this->analyzing[$dataClass]);
        }

        return null;
    }

    private function extractFromReflection(string $dataClass): ?SchemaObject
    {
        try {
            $reflection = new \ReflectionClass($dataClass);
        } catch (\Throwable) {
            return null;
        }

        $properties = [];
        $required = [];
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return SchemaObject::object();
        }

        // Filter out Spatie internal parameters
        $spatieInternalProps = [
            '_additional',
            '_wrap',
        ];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (in_array($name, $spatieInternalProps, true)) {
                continue;
            }

            $propSchema = $this->extractParameterSchema($param);
            $properties[$name] = $propSchema;

            if (! $param->isOptional() && ! $param->allowsNull()) {
                $required[] = $name;
            }
        }

        // Also check public properties that aren't constructor promoted
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (isset($properties[$name]) || in_array($name, $spatieInternalProps, true)) {
                continue;
            }

            $propSchema = $this->extractPropertySchema($prop);
            $properties[$name] = $propSchema;
        }

        return SchemaObject::object($properties, ! empty($required) ? $required : null);
    }

    private function extractParameterSchema(\ReflectionParameter $param): SchemaObject
    {
        $type = $param->getType();

        if ($type === null) {
            return new SchemaObject(type: 'string');
        }

        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();

            // Check for nested Data classes
            if (! $type->isBuiltin() && class_exists($typeName) && is_subclass_of($typeName, \Spatie\LaravelData\Data::class)) {
                $nestedSchema = $this->analyze($typeName);
                if ($nestedSchema !== null) {
                    if ($type->allowsNull()) {
                        $nestedSchema = clone $nestedSchema;
                        $nestedSchema->nullable = true;
                    }

                    return $nestedSchema;
                }
            }

            // Check for Data collections
            if (! $type->isBuiltin() && class_exists($typeName)) {
                if (is_subclass_of($typeName, \Illuminate\Support\Collection::class)) {
                    return SchemaObject::array(SchemaObject::object());
                }

                // Try ClassSchemaResolver for other class types (DTOs, value objects)
                if ($this->classResolver !== null) {
                    $resolved = $this->classResolver->resolve($typeName);
                    if ($resolved !== null) {
                        if ($type->allowsNull()) {
                            $resolved = clone $resolved;
                            $resolved->nullable = true;
                        }

                        return $resolved;
                    }
                }
            }

            $schema = $this->typeMapper->mapReflectionType($type);

            if ($param->isDefaultValueAvailable()) {
                try {
                    $schema->default = $param->getDefaultValue();
                } catch (\Throwable) {
                    // Skip
                }
            }

            return $schema;
        }

        return $this->typeMapper->mapReflectionType($type);
    }

    private function extractPropertySchema(\ReflectionProperty $prop): SchemaObject
    {
        $type = $prop->getType();

        if ($type === null) {
            return new SchemaObject(type: 'string');
        }

        return $this->typeMapper->mapReflectionType($type);
    }
}
