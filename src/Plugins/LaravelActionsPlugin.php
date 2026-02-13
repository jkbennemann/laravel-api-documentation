<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;

/**
 * Plugin for lorisleiva/laravel-actions package.
 *
 * Detects classes using the AsController trait and analyzes the handle()
 * method parameters to extract request body schemas.
 */
class LaravelActionsPlugin implements Plugin, RequestBodyExtractor
{
    private const AS_CONTROLLER_TRAIT = 'Lorisleiva\\Actions\\Concerns\\AsController';

    private TypeMapper $typeMapper;

    public function __construct()
    {
        $this->typeMapper = new TypeMapper;
    }

    public function name(): string
    {
        return 'laravel-actions';
    }

    public function boot(PluginRegistry $registry): void
    {
        if (! trait_exists(self::AS_CONTROLLER_TRAIT)) {
            return;
        }

        $registry->addRequestExtractor($this, 85);
    }

    public function priority(): int
    {
        return 35;
    }

    public function extract(AnalysisContext $ctx): ?SchemaResult
    {
        $controllerClass = $ctx->controllerClass();
        if ($controllerClass === null || ! class_exists($controllerClass)) {
            return null;
        }

        // Check if controller uses AsController trait
        if (! $this->usesAsController($controllerClass)) {
            return null;
        }

        // Actions use handle() method, but the route calls __invoke which delegates to handle()
        // The handle() method's parameters define the request data
        try {
            $ref = new \ReflectionClass($controllerClass);

            if (! $ref->hasMethod('handle')) {
                return null;
            }

            $handleMethod = $ref->getMethod('handle');
        } catch (\Throwable) {
            return null;
        }

        // Only extract for methods that accept data (POST/PUT/PATCH)
        $httpMethod = strtoupper($ctx->route->httpMethod());
        if (! in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        $schema = $this->buildSchemaFromHandleMethod($handleMethod, $controllerClass);
        if ($schema === null) {
            return null;
        }

        return new SchemaResult(
            schema: $schema,
            description: 'Request body',
            source: 'plugin:laravel-actions',
        );
    }

    private function usesAsController(string $className): bool
    {
        try {
            $ref = new \ReflectionClass($className);
            $traits = $this->getAllTraits($ref);

            return in_array(self::AS_CONTROLLER_TRAIT, $traits, true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get all traits used by a class (including parent classes and nested traits).
     *
     * @return string[]
     */
    private function getAllTraits(\ReflectionClass $ref): array
    {
        $traits = [];

        do {
            foreach ($ref->getTraitNames() as $traitName) {
                $traits[] = $traitName;

                // Check nested traits
                try {
                    $traitRef = new \ReflectionClass($traitName);
                    foreach ($traitRef->getTraitNames() as $nestedTrait) {
                        $traits[] = $nestedTrait;
                    }
                } catch (\Throwable) {
                    // Skip
                }
            }
        } while ($ref = $ref->getParentClass());

        return array_unique($traits);
    }

    private function buildSchemaFromHandleMethod(\ReflectionMethod $method, string $controllerClass): ?SchemaObject
    {
        $properties = [];
        $required = [];

        // Actions can define rules() for validation — check that first
        $rules = $this->extractRules($controllerClass);
        if ($rules !== null) {
            return $this->buildSchemaFromRules($rules);
        }

        // Fall back to analyzing handle() parameters
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();

            // Skip injected service dependencies (type-hinted classes that aren't DTOs)
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $className = $type->getName();

                // Skip framework injections
                if ($this->isInjectedDependency($className)) {
                    continue;
                }

                // If it's a Spatie Data object, let the SpatieDataPlugin handle it
                if (class_exists('Spatie\LaravelData\Data') && is_subclass_of($className, 'Spatie\LaravelData\Data')) {
                    return null;
                }
            }

            $name = $param->getName();
            $schema = $type !== null ? $this->typeMapper->mapReflectionType($type) : SchemaObject::string();

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

        if (empty($properties)) {
            return null;
        }

        return SchemaObject::object($properties, ! empty($required) ? $required : null);
    }

    /**
     * Try to extract validation rules from the action's rules() method.
     *
     * @return array<string, mixed>|null
     */
    private function extractRules(string $className): ?array
    {
        try {
            $ref = new \ReflectionClass($className);

            if (! $ref->hasMethod('rules')) {
                return null;
            }

            $rulesMethod = $ref->getMethod('rules');

            // Only use if the rules method is defined in the action class itself
            if ($rulesMethod->getDeclaringClass()->getName() !== $className) {
                return null;
            }

            // Try to call rules() statically or via instantiation
            $instance = $ref->newInstanceWithoutConstructor();

            return $instance->rules();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function buildSchemaFromRules(array $rules): SchemaObject
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            $ruleList = is_string($fieldRules) ? explode('|', $fieldRules) : (array) $fieldRules;

            $type = 'string';
            $nullable = false;
            $isRequired = false;
            $enumValues = null;
            $format = null;

            foreach ($ruleList as $rule) {
                $rule = is_string($rule) ? $rule : '';
                $ruleName = explode(':', $rule)[0];
                $ruleParams = str_contains($rule, ':') ? explode(',', explode(':', $rule, 2)[1]) : [];

                match ($ruleName) {
                    'required' => $isRequired = true,
                    'nullable' => $nullable = true,
                    'integer', 'numeric' => $type = 'integer',
                    'boolean', 'bool' => $type = 'boolean',
                    'array' => $type = 'array',
                    'email' => $format = 'email',
                    'url' => $format = 'uri',
                    'uuid' => $format = 'uuid',
                    'date', 'date_format' => $format = 'date-time',
                    'in' => $enumValues = $ruleParams,
                    default => null,
                };
            }

            $schema = new SchemaObject(
                type: $type,
                format: $format,
                nullable: $nullable,
                enum: $enumValues,
            );

            $properties[$field] = $schema;

            if ($isRequired) {
                $required[] = $field;
            }
        }

        return SchemaObject::object($properties, ! empty($required) ? $required : null);
    }

    private function isInjectedDependency(string $className): bool
    {
        $frameworkPrefixes = [
            'Illuminate\\',
            'Symfony\\',
            'Psr\\',
            'App\\Services\\',
            'App\\Repositories\\',
        ];

        foreach ($frameworkPrefixes as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }

        // Check if class is a known request type
        if (class_exists($className)) {
            try {
                $ref = new \ReflectionClass($className);

                return $ref->isSubclassOf('Illuminate\Http\Request')
                    || $ref->getName() === 'Illuminate\Http\Request';
            } catch (\Throwable) {
                // Fall through
            }
        }

        return false;
    }
}
