<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Schema;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class EloquentModelAnalyzer
{
    /** @var array<string, array<string, SchemaObject|null>> Per-model property type cache */
    private array $modelCache = [];

    /** @var array<string, string|null> Resource class → model class cache */
    private array $resourceModelCache = [];

    public function __construct(
        private readonly TypeMapper $typeMapper,
    ) {}

    /**
     * Get the schema type for a specific property on an Eloquent model.
     */
    public function getPropertyType(string $modelClass, string $property): ?SchemaObject
    {
        if (! class_exists($modelClass)) {
            return null;
        }

        $cacheKey = $modelClass.'::'.$property;
        if (array_key_exists($cacheKey, $this->modelCache[$modelClass] ?? [])) {
            return $this->modelCache[$modelClass][$property];
        }

        $schema = $this->resolvePropertyType($modelClass, $property);
        $this->modelCache[$modelClass][$property] = $schema;

        return $schema;
    }

    /**
     * Check if a property should be included in API output for the given model.
     * Respects both $hidden and $visible arrays.
     */
    public function shouldExposeProperty(string $modelClass, string $property): bool
    {
        $visible = $this->getVisibleFields($modelClass);
        if (! empty($visible)) {
            return in_array($property, $visible, true);
        }

        return ! $this->isHidden($modelClass, $property);
    }

    /**
     * Detect the Eloquent model class that a JsonResource wraps.
     */
    public function getModelForResource(string $resourceClass): ?string
    {
        if (array_key_exists($resourceClass, $this->resourceModelCache)) {
            return $this->resourceModelCache[$resourceClass];
        }

        $model = $this->detectModelFromResource($resourceClass);
        $this->resourceModelCache[$resourceClass] = $model;

        return $model;
    }

    /**
     * Check if a property is hidden on the given model.
     */
    public function isHidden(string $modelClass, string $property): bool
    {
        $hidden = $this->getHiddenFields($modelClass);

        return in_array($property, $hidden, true);
    }

    /**
     * Get the $hidden array from an Eloquent model.
     *
     * @return string[]
     */
    public function getHiddenFields(string $modelClass): array
    {
        return $this->getModelArrayProperty($modelClass, 'hidden');
    }

    /**
     * Get the $visible array from an Eloquent model.
     *
     * @return string[]
     */
    public function getVisibleFields(string $modelClass): array
    {
        return $this->getModelArrayProperty($modelClass, 'visible');
    }

    /**
     * Get the $appends array from an Eloquent model.
     * These are computed attributes backed by accessors.
     *
     * @return string[]
     */
    public function getAppends(string $modelClass): array
    {
        return $this->getModelArrayProperty($modelClass, 'appends');
    }

    /**
     * @return string[]
     */
    private function getModelArrayProperty(string $modelClass, string $propertyName): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        try {
            $reflection = new \ReflectionClass($modelClass);
            if (! $reflection->hasProperty($propertyName)) {
                return [];
            }

            $prop = $reflection->getProperty($propertyName);
            $prop->setAccessible(true);
            $instance = $reflection->newInstanceWithoutConstructor();
            $value = $prop->getValue($instance);

            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolvePropertyType(string $modelClass, string $property): ?SchemaObject
    {
        try {
            $reflection = new \ReflectionClass($modelClass);
        } catch (\Throwable) {
            return null;
        }

        // 1. Check $casts property
        $schema = $this->resolveFromCasts($reflection, $property);
        if ($schema !== null) {
            return $schema;
        }

        // 2. Check accessor methods: get{Prop}Attribute
        $schema = $this->resolveFromAccessor($reflection, $property);
        if ($schema !== null) {
            return $schema;
        }

        // 3. Check $dates property (deprecated but still common)
        if ($this->isInDatesProperty($reflection, $property)) {
            return SchemaObject::string('date-time');
        }

        // 4. Check PHPDoc @property annotations (including parent classes / IDE helper stubs)
        $schema = $this->resolveFromPhpDocProperty($reflection, $property);
        if ($schema !== null) {
            return $schema;
        }

        // 5. Check database column type
        $schema = $this->resolveFromDatabaseColumn($reflection, $property);
        if ($schema !== null) {
            return $schema;
        }

        return null;
    }

    private function detectModelFromPhpDoc(\ReflectionClass $reflection): ?string
    {
        $docComment = $reflection->getDocComment();
        if ($docComment === false) {
            return null;
        }

        // Match @mixin ClassName
        if (preg_match('/@mixin\s+([\\\\a-zA-Z0-9_]+)/', $docComment, $matches)) {
            $className = $matches[1];

            // Resolve relative class names via use statements
            if (! str_contains($className, '\\')) {
                $fileName = $reflection->getFileName();
                if ($fileName && file_exists($fileName)) {
                    $code = file_get_contents($fileName);
                    if (preg_match('/use\s+([\\\\a-zA-Z0-9_]+\\\\'.preg_quote($className, '/').')\s*;/', $code, $useMatch)) {
                        $className = $useMatch[1];
                    }
                }
            }

            if (class_exists($className) && is_subclass_of($className, 'Illuminate\Database\Eloquent\Model')) {
                return $className;
            }
        }

        return null;
    }

    private function resolveFromCasts(\ReflectionClass $reflection, string $property): ?SchemaObject
    {
        $casts = $this->getCastsArray($reflection);
        if ($casts === null || ! isset($casts[$property])) {
            return null;
        }

        $castType = $casts[$property];

        return $this->castToSchema($castType);
    }

    private function getCastsArray(\ReflectionClass $reflection): ?array
    {
        // Try $casts property
        if ($reflection->hasProperty('casts')) {
            try {
                $prop = $reflection->getProperty('casts');
                $prop->setAccessible(true);
                $instance = $reflection->newInstanceWithoutConstructor();
                $value = $prop->getValue($instance);
                if (is_array($value)) {
                    return $value;
                }
            } catch (\Throwable) {
                // Skip
            }
        }

        // Try casts() method (Laravel 11+)
        if ($reflection->hasMethod('casts')) {
            try {
                $method = $reflection->getMethod('casts');
                if ($method->isProtected() || $method->isPublic()) {
                    $method->setAccessible(true);
                    $instance = $reflection->newInstanceWithoutConstructor();
                    $result = $method->invoke($instance);
                    if (is_array($result)) {
                        return $result;
                    }
                }
            } catch (\Throwable) {
                // Skip
            }
        }

        return null;
    }

    private function resolveFromAccessor(\ReflectionClass $reflection, string $property): ?SchemaObject
    {
        // Laravel 9+ style: property() : Attribute
        $methodName = str_replace('_', '', lcfirst(ucwords($property, '_')));
        if ($reflection->hasMethod($methodName)) {
            try {
                $method = $reflection->getMethod($methodName);
                $returnType = $method->getReturnType();
                if ($returnType instanceof \ReflectionNamedType) {
                    $typeName = $returnType->getName();
                    if ($typeName === 'Illuminate\Database\Eloquent\Casts\Attribute') {
                        // Can't easily infer the generic types, return null to fall through
                        return null;
                    }
                }
            } catch (\Throwable) {
                // Skip
            }
        }

        // Legacy style: get{Property}Attribute
        $legacyAccessor = 'get'.str_replace('_', '', ucwords($property, '_')).'Attribute';
        if ($reflection->hasMethod($legacyAccessor)) {
            try {
                $method = $reflection->getMethod($legacyAccessor);
                $returnType = $method->getReturnType();
                if ($returnType !== null) {
                    return $this->typeMapper->mapReflectionType($returnType);
                }
            } catch (\Throwable) {
                // Skip
            }
        }

        return null;
    }

    private function isInDatesProperty(\ReflectionClass $reflection, string $property): bool
    {
        if (! $reflection->hasProperty('dates')) {
            return false;
        }

        try {
            $prop = $reflection->getProperty('dates');
            $prop->setAccessible(true);
            $instance = $reflection->newInstanceWithoutConstructor();
            $dates = $prop->getValue($instance);

            return is_array($dates) && in_array($property, $dates, true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveFromPhpDocProperty(\ReflectionClass $reflection, string $property): ?SchemaObject
    {
        // Walk up the class hierarchy (includes IDE helper stubs / base classes)
        $current = $reflection;
        while ($current !== false) {
            $docComment = $current->getDocComment();
            if ($docComment !== false) {
                // Match @property Type $name or @property-read Type $name
                if (preg_match_all('/@property(?:-read)?\s+([^\s$]+)\s+\$(\w+)/', $docComment, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        if ($match[2] === $property) {
                            return $this->typeMapper->mapPhpType($match[1]);
                        }
                    }
                }
            }
            $current = $current->getParentClass();
        }

        return null;
    }

    private function resolveFromDatabaseColumn(\ReflectionClass $reflection, string $property): ?SchemaObject
    {
        try {
            $instance = $reflection->newInstanceWithoutConstructor();
            $table = $instance->getTable();
            $connection = $instance->getConnectionName();

            $schema = \Illuminate\Support\Facades\Schema::connection($connection);
            if (! $schema->hasColumn($table, $property)) {
                return null;
            }

            $columnType = $schema->getColumnType($table, $property);

            return match ($columnType) {
                'integer', 'bigint', 'smallint', 'tinyint' => SchemaObject::integer(),
                'float', 'double', 'decimal' => SchemaObject::number('double'),
                'boolean' => SchemaObject::boolean(),
                'date' => SchemaObject::string('date'),
                'datetime', 'timestamp' => SchemaObject::string('date-time'),
                'json', 'jsonb' => SchemaObject::object(),
                'text', 'string', 'char', 'varchar' => SchemaObject::string(),
                default => SchemaObject::string(),
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function castToSchema(string $castType): ?SchemaObject
    {
        // Handle cast classes like 'decimal:2'
        $baseCast = explode(':', $castType)[0];

        return match (true) {
            $baseCast === 'integer' || $baseCast === 'int' => SchemaObject::integer(),
            $baseCast === 'float' || $baseCast === 'double' || $baseCast === 'real' => SchemaObject::number('double'),
            $baseCast === 'decimal' => SchemaObject::number('double'),
            $baseCast === 'string' => SchemaObject::string(),
            $baseCast === 'boolean' || $baseCast === 'bool' => SchemaObject::boolean(),
            $baseCast === 'array' || $baseCast === 'json' => new SchemaObject(type: 'array', items: SchemaObject::string()),
            $baseCast === 'collection' => new SchemaObject(type: 'array', items: SchemaObject::string()),
            $baseCast === 'object' => SchemaObject::object(),
            $baseCast === 'date' => SchemaObject::string('date'),
            $baseCast === 'datetime' || $baseCast === 'immutable_date' || $baseCast === 'immutable_datetime' => SchemaObject::string('date-time'),
            $baseCast === 'timestamp' => SchemaObject::integer(),
            $baseCast === 'encrypted' => SchemaObject::string(),
            $baseCast === 'hashed' => SchemaObject::string(),
            enum_exists($baseCast) => $this->typeMapper->mapEnum($baseCast),
            class_exists($baseCast) => $this->resolveCustomCastType($baseCast),
            default => null,
        };
    }

    /**
     * Resolve the return type of a custom cast class's get() method.
     */
    private function resolveCustomCastType(string $castClass): ?SchemaObject
    {
        try {
            $reflection = new \ReflectionClass($castClass);

            // Check if it implements CastsAttributes or CastsInboundAttributes
            $isCast = $reflection->implementsInterface('Illuminate\Contracts\Database\Eloquent\CastsAttributes')
                || $reflection->implementsInterface('Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes');

            if (! $isCast) {
                return null;
            }

            // Look at get() method return type
            if ($reflection->hasMethod('get')) {
                $method = $reflection->getMethod('get');
                $returnType = $method->getReturnType();
                if ($returnType instanceof \ReflectionNamedType) {
                    return $this->typeMapper->mapReflectionType($returnType);
                }
            }
        } catch (\Throwable) {
            // Skip
        }

        return null;
    }

    private function detectModelFromResource(string $resourceClass): ?string
    {
        try {
            $reflection = new \ReflectionClass($resourceClass);
        } catch (\Throwable) {
            return null;
        }

        // 1. Check constructor type-hints (common pattern: __construct(public User $resource))
        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                    $typeName = $type->getName();
                    if (class_exists($typeName) && is_subclass_of($typeName, 'Illuminate\Database\Eloquent\Model')) {
                        return $typeName;
                    }
                }
            }
        }

        // 2. Check $resource property type hint via PHPDoc or type (if overridden)
        if ($reflection->hasProperty('resource')) {
            $prop = $reflection->getProperty('resource');
            $propType = $prop->getType();
            if ($propType instanceof \ReflectionNamedType && ! $propType->isBuiltin()) {
                $typeName = $propType->getName();
                if (class_exists($typeName) && is_subclass_of($typeName, 'Illuminate\Database\Eloquent\Model')) {
                    return $typeName;
                }
            }
        }

        // 3. Check PHPDoc @mixin tag (common pattern: @mixin User)
        $model = $this->detectModelFromPhpDoc($reflection);
        if ($model !== null) {
            return $model;
        }

        // 4. Convention-based: UserResource → App\Models\User or Domain\*\Models\User
        $baseName = class_basename($resourceClass);
        $modelName = preg_replace('/(Resource|Collection)$/', '', $baseName);
        if ($modelName !== '' && $modelName !== $baseName) {
            $commonNamespaces = [
                'App\\Models\\',
                'App\\',
            ];

            // Also try the resource's namespace with Models sub-namespace
            $resourceNamespace = substr($resourceClass, 0, (int) strrpos($resourceClass, '\\'));
            $parentNamespace = substr($resourceNamespace, 0, (int) strrpos($resourceNamespace, '\\'));
            if ($parentNamespace !== '') {
                array_unshift($commonNamespaces, $parentNamespace.'\\Models\\');
                array_unshift($commonNamespaces, $parentNamespace.'\\');
            }

            foreach ($commonNamespaces as $ns) {
                $fqcn = $ns.$modelName;
                if (class_exists($fqcn) && is_subclass_of($fqcn, 'Illuminate\Database\Eloquent\Model')) {
                    return $fqcn;
                }
            }
        }

        return null;
    }
}
