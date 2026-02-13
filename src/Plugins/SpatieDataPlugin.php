<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\ClassSchemaResolver;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;

class SpatieDataPlugin implements Plugin, RequestBodyExtractor
{
    private TypeMapper $typeMapper;

    private ?SchemaRegistry $registry = null;

    private ?ClassSchemaResolver $classResolver = null;

    public function __construct()
    {
        $this->typeMapper = new TypeMapper;
    }

    public function name(): string
    {
        return 'spatie-data';
    }

    public function boot(PluginRegistry $registry): void
    {
        if (! class_exists(\Spatie\LaravelData\Data::class)) {
            return;
        }

        $registry->addRequestExtractor($this, 90);
    }

    public function priority(): int
    {
        return 45;
    }

    public function setRegistry(SchemaRegistry $registry): void
    {
        $this->registry = $registry;
    }

    public function setClassResolver(ClassSchemaResolver $resolver): void
    {
        $this->classResolver = $resolver;
    }

    public function extract(AnalysisContext $ctx): ?SchemaResult
    {
        if ($ctx->reflectionMethod === null) {
            return null;
        }

        foreach ($ctx->reflectionMethod->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();
            if (! class_exists($className) || ! is_subclass_of($className, \Spatie\LaravelData\Data::class)) {
                continue;
            }

            $schema = $this->buildSchemaFromDataClass($className);
            if ($schema === null) {
                continue;
            }

            return new SchemaResult(
                schema: $schema,
                description: 'Request body',
                source: 'plugin:spatie-data',
            );
        }

        return null;
    }

    private function buildSchemaFromDataClass(string $className): ?SchemaObject
    {
        // Delegate to ClassSchemaResolver for recursive resolution
        if ($this->classResolver !== null) {
            return $this->classResolver->resolve($className);
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\Throwable) {
            return null;
        }

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
            $schema = $type !== null ? $this->typeMapper->mapReflectionType($type) : SchemaObject::string();

            if ($type instanceof \ReflectionNamedType && str_contains($type->getName(), 'Lazy')) {
                $schema->nullable = true;
            }

            if ($param->isDefaultValueAvailable()) {
                try {
                    $schema->default = $param->getDefaultValue();
                } catch (\Throwable) {
                    // Skip
                }
            }

            $properties[$name] = $schema;

            if (! $param->isOptional() && ! $param->allowsNull()) {
                $required[] = $name;
            }
        }

        $schemaObj = SchemaObject::object($properties, ! empty($required) ? $required : null);

        if ($this->registry !== null) {
            $refOrSchema = $this->registry->registerIfComplex(class_basename($className), $schemaObj);

            return $refOrSchema instanceof SchemaObject ? $refOrSchema : SchemaObject::ref($refOrSchema);
        }

        return $schemaObj;
    }
}
