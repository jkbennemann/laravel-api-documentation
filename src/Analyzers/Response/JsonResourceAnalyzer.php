<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Response;

use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use JkBennemann\LaravelApiDocumentation\Cache\AstCache;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Schema\ClassSchemaResolver;
use JkBennemann\LaravelApiDocumentation\Schema\EloquentModelAnalyzer;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

class JsonResourceAnalyzer
{
    private TypeMapper $typeMapper;

    /** @var array<string, SchemaObject|null> Cache to prevent infinite recursion */
    private array $analyzing = [];

    private array $config;

    private ?ClassSchemaResolver $classResolver = null;

    private ?EloquentModelAnalyzer $modelAnalyzer = null;

    private AstCache $astCache;

    public function __construct(
        private readonly SchemaRegistry $registry,
        array $config = [],
        ?AstCache $astCache = null,
    ) {
        $this->typeMapper = new TypeMapper;
        $this->config = $config;
        $this->astCache = $astCache ?? new AstCache(sys_get_temp_dir(), 0);
    }

    public function setClassResolver(ClassSchemaResolver $resolver): void
    {
        $this->classResolver = $resolver;
    }

    public function setModelAnalyzer(EloquentModelAnalyzer $analyzer): void
    {
        $this->modelAnalyzer = $analyzer;
    }

    /**
     * Detect the wrap key for a JsonResource class.
     * Returns null when wrapping is explicitly disabled ($wrap = null) or class doesn't exist.
     * Returns 'data' by default (Laravel's default wrap key).
     */
    public static function detectWrapKey(string $resourceClass): ?string
    {
        try {
            $reflection = new \ReflectionClass($resourceClass);
            if ($reflection->hasProperty('wrap')) {
                $prop = $reflection->getProperty('wrap');
                // $wrap is a static property on JsonResource
                $value = $prop->getDefaultValue();

                // null means wrapping is disabled
                if ($value === null) {
                    return null;
                }

                return is_string($value) ? $value : 'data';
            }

            // Default Laravel behavior: wrap with 'data'
            return 'data';
        } catch (\Throwable) {
            return 'data';
        }
    }

    /**
     * Analyze a JsonResource class and return its schema.
     */
    public function analyze(string $resourceClass): ?SchemaObject
    {
        $resourceClass = ltrim($resourceClass, '\\');

        // Prevent infinite recursion (array_key_exists because value is null, and isset(null) is false)
        if (array_key_exists($resourceClass, $this->analyzing)) {
            return $this->analyzing[$resourceClass];
        }
        $this->analyzing[$resourceClass] = null;

        try {
            // Check if it's a ResourceCollection
            if (is_subclass_of($resourceClass, \Illuminate\Http\Resources\Json\ResourceCollection::class)) {
                return $this->analyzeCollection($resourceClass);
            }

            // Try #[Parameter] attributes first (highest quality, explicit)
            $schema = $this->extractSchemaFromParameterAttributes($resourceClass);

            // Fall back to AST analysis of toArray()
            if ($schema === null) {
                $schema = $this->analyzeToArrayMethod($resourceClass);
            }

            // Fall back to constructor parameter type analysis
            // (for Resources without toArray() that wrap typed DTOs)
            if ($schema === null) {
                $schema = $this->analyzeConstructorParameters($resourceClass);
            }

            if ($schema !== null) {
                $name = class_basename($resourceClass);
                $this->analyzing[$resourceClass] = $schema;

                // Register as component if complex enough
                $refOrSchema = $this->registry->registerIfComplex($name, $schema);

                return $refOrSchema instanceof SchemaObject ? $refOrSchema : SchemaObject::ref($refOrSchema);
            }
        } finally {
            unset($this->analyzing[$resourceClass]);
        }

        return null;
    }

    private function analyzeCollection(string $collectionClass): ?SchemaObject
    {
        // Try to find the underlying resource class
        try {
            $reflection = new \ReflectionClass($collectionClass);

            // Check $collects property
            if ($reflection->hasProperty('collects')) {
                $prop = $reflection->getProperty('collects');
                $prop->setAccessible(true);
                $instance = $reflection->newInstanceWithoutConstructor();
                $collectsClass = $prop->getValue($instance);
                if (is_string($collectsClass) && class_exists($collectsClass)) {
                    $itemSchema = $this->analyze($collectsClass);
                    if ($itemSchema !== null) {
                        return SchemaObject::array($itemSchema);
                    }
                }
            }
        } catch (\Throwable) {
            // Skip
        }

        return SchemaObject::array(SchemaObject::object());
    }

    public function extractSchemaFromParameterAttributes(string $resourceClass): ?SchemaObject
    {
        try {
            $reflection = new \ReflectionClass($resourceClass);
            $attributes = $reflection->getAttributes(Parameter::class);

            if (empty($attributes)) {
                return null;
            }

            $properties = [];
            $required = [];

            foreach ($attributes as $attribute) {
                /** @var Parameter $param */
                $param = $attribute->newInstance();

                // Handle nested resource references
                if ($param->resource !== null) {
                    $nestedClass = ltrim($param->resource, '\\');
                    if (class_exists($nestedClass) && is_subclass_of($nestedClass, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                        $nestedSchema = $this->analyze($nestedClass);
                        if ($nestedSchema !== null) {
                            if ($param->type === 'array') {
                                $nestedSchema = SchemaObject::array($nestedSchema);
                            }
                            if ($param->nullable) {
                                $nestedSchema->nullable = true;
                            }
                            if ($param->description !== '') {
                                $nestedSchema->description = $param->description;
                            }
                            $properties[$param->name] = $nestedSchema;

                            continue;
                        }
                    }
                    // Non-JsonResource reference: fall back to TypeMapper
                    $nestedSchema = $this->typeMapper->mapClassName($nestedClass);
                    if ($param->nullable) {
                        $nestedSchema->nullable = true;
                    }
                    $properties[$param->name] = $nestedSchema;

                    continue;
                }

                $schema = new SchemaObject(
                    type: $param->type,
                    format: $param->format,
                    description: $param->description !== '' ? $param->description : null,
                    example: $param->example,
                    nullable: $param->nullable,
                    minLength: $param->minLength,
                    maxLength: $param->maxLength,
                    deprecated: $param->deprecated,
                );

                $properties[$param->name] = $schema;

                if ($param->required) {
                    $required[] = $param->name;
                }
            }

            return SchemaObject::object($properties, ! empty($required) ? $required : null);
        } catch (\Throwable) {
            return null;
        }
    }

    private function analyzeToArrayMethod(string $resourceClass): ?SchemaObject
    {
        try {
            $reflection = new \ReflectionClass($resourceClass);
            $fileName = $reflection->getFileName();

            if (! $fileName || ! file_exists($fileName)) {
                return null;
            }

            $stmts = $this->astCache->parseFile($fileName);
            if ($stmts === null) {
                return null;
            }

            $nodeFinder = new NodeFinder;
            $methods = $nodeFinder->findInstanceOf($stmts, ClassMethod::class);

            foreach ($methods as $method) {
                if ($method->name->toString() === 'toArray') {
                    return $this->extractSchemaFromToArray($method, $resourceClass);
                }
            }
        } catch (\Throwable) {
            // Parsing failed
        }

        return null;
    }

    private function extractSchemaFromToArray(ClassMethod $method, string $resourceClass): ?SchemaObject
    {
        $nodeFinder = new NodeFinder;
        $returns = $nodeFinder->findInstanceOf($method->stmts ?? [], Return_::class);

        foreach ($returns as $return) {
            if ($return->expr instanceof Array_) {
                return $this->extractSchemaFromArray($return->expr, $resourceClass);
            }

            // Trace variable returns: `return $result` → find `$result = [...]`
            if ($return->expr instanceof Expr\Variable && is_string($return->expr->name)) {
                $arrayExpr = $this->traceVariableToArray($return->expr->name, $method);
                if ($arrayExpr !== null) {
                    return $this->extractSchemaFromArray($arrayExpr, $resourceClass);
                }
            }
        }

        return null;
    }

    /**
     * Find the initial array literal assignment to a variable within a method.
     * Handles: `$var = [...]` and `$var = [...] + [...]`
     */
    public function traceVariableToArray(string $varName, ClassMethod $method): ?Array_
    {
        foreach ($method->stmts ?? [] as $stmt) {
            if (! $stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;
            if (! $expr instanceof Expr\Assign) {
                continue;
            }

            if (! $expr->var instanceof Expr\Variable || $expr->var->name !== $varName) {
                continue;
            }

            // Direct: $var = [...]
            if ($expr->expr instanceof Array_) {
                return $expr->expr;
            }

            // Array merge via +: $var = [...] + [...]
            if ($expr->expr instanceof Expr\BinaryOp\Plus) {
                if ($expr->expr->left instanceof Array_) {
                    return $expr->expr->left;
                }
            }

            // Found the variable but assigned to something non-array — stop tracing
            break;
        }

        return null;
    }

    public function extractSchemaFromArray(Array_ $array, string $resourceClass): SchemaObject
    {
        $properties = [];
        $required = [];
        $dynamicValueSchemas = [];

        foreach ($array->items as $item) {
            if (! $item instanceof ArrayItem) {
                continue;
            }

            // Items with no key: merge()/mergeWhen() calls inject properties
            if ($item->key === null) {
                $mergedProps = $this->extractMergeProperties($item->value, $resourceClass);
                if ($mergedProps !== null) {
                    foreach ($mergedProps['properties'] as $name => $schema) {
                        $properties[$name] = $schema;
                    }
                    foreach ($mergedProps['required'] as $name) {
                        $required[] = $name;
                    }

                    continue;
                }

                // Dynamic key (e.g. enum method call, variable) — collect value schemas
                $dynamicValueSchemas[] = $this->inferPropertySchema($item->value, $resourceClass);

                continue;
            }

            if ($item->key instanceof String_) {
                $key = $item->key->value;
                $propSchema = $this->inferPropertySchema($item->value, $resourceClass);
                $properties[$key] = $propSchema;

                // Mark as required unless it's a conditional field
                if (! $this->isConditionalField($item->value)) {
                    $required[] = $key;
                }
            } else {
                // Dynamic key (e.g. enum method call, variable) — collect value schemas
                $dynamicValueSchemas[] = $this->inferPropertySchema($item->value, $resourceClass);
            }
        }

        // If we have known properties, return a normal object schema
        if (! empty($properties)) {
            return SchemaObject::object($properties, ! empty($required) ? $required : null);
        }

        // All keys are dynamic — this is a map/dictionary.
        // Use additionalProperties to describe the value type.
        if (! empty($dynamicValueSchemas)) {
            $valueSchema = $dynamicValueSchemas[0];

            return new SchemaObject(
                type: 'object',
                extra: ['additionalProperties' => $valueSchema->jsonSerialize()],
            );
        }

        return SchemaObject::object();
    }

    private function inferPropertySchema(Node $value, string $resourceClass): SchemaObject
    {
        // $this->id, $this->name, etc.
        if ($value instanceof Expr\PropertyFetch || $value instanceof Expr\NullsafePropertyFetch) {
            return $this->inferFromPropertyAccess($value, $resourceClass);
        }

        // $this->when($condition, $value)
        if ($value instanceof Expr\MethodCall && $value->name instanceof Node\Identifier) {
            $methodName = $value->name->toString();

            if ($methodName === 'when' || $methodName === 'whenLoaded' || $methodName === 'whenNotNull') {
                return $this->inferConditionalField($value, $resourceClass);
            }

            // $this->merge([...]) or $this->mergeWhen(cond, [...])
            if ($methodName === 'merge' || $methodName === 'mergeWhen') {
                // Try extracting properties from the merge array
                $mergedProps = $this->extractMergeProperties($value, $resourceClass);
                if ($mergedProps !== null && ! empty($mergedProps['properties'])) {
                    // Return a schema object with the merged properties
                    return SchemaObject::object(
                        $mergedProps['properties'],
                        ! empty($mergedProps['required']) ? $mergedProps['required'] : null,
                    );
                }

                return SchemaObject::object();
            }

            // Method call type inference from config
            $methodTypes = $this->config['smart_responses']['method_types'] ?? [];
            if (isset($methodTypes[$methodName])) {
                $mapping = $methodTypes[$methodName];

                return new SchemaObject(
                    type: $mapping['type'],
                    format: $mapping['format'] ?? null,
                );
            }
        }

        // Static call: SomeResource::make($this->relation)
        if ($value instanceof Expr\StaticCall && $value->class instanceof Node\Name) {
            $className = $value->class->toString();
            if (str_ends_with($className, 'Resource')) {
                // Nested resource
                $schema = $this->tryResolveResource($className, $resourceClass);
                if ($schema !== null) {
                    return $schema;
                }
            }
        }

        // new SomeResource(...)
        if ($value instanceof Expr\New_ && $value->class instanceof Node\Name) {
            $className = $value->class->toString();
            $schema = $this->tryResolveResource($className, $resourceClass);
            if ($schema !== null) {
                return $schema;
            }
        }

        // String literal
        if ($value instanceof String_) {
            return SchemaObject::string();
        }

        // Integer literal
        if ($value instanceof Node\Scalar\Int_ || $value instanceof Node\Scalar\LNumber) {
            return SchemaObject::integer();
        }

        // Float literal
        if ($value instanceof Node\Scalar\Float_ || $value instanceof Node\Scalar\DNumber) {
            return SchemaObject::number();
        }

        // Boolean (true/false)
        if ($value instanceof Expr\ConstFetch) {
            $name = strtolower($value->name->toString());
            if (in_array($name, ['true', 'false'])) {
                return SchemaObject::boolean();
            }
            if ($name === 'null') {
                return new SchemaObject(type: 'string', nullable: true);
            }
        }

        // Array literal
        if ($value instanceof Array_) {
            if ($this->isSequentialArray($value)) {
                return SchemaObject::array(SchemaObject::string());
            }

            return $this->extractSchemaFromArray($value, $resourceClass);
        }

        // Ternary
        if ($value instanceof Expr\Ternary) {
            if ($value->if !== null) {
                $schema = $this->inferPropertySchema($value->if, $resourceClass);
                $schema->nullable = true;

                return $schema;
            }
        }

        // Default
        return new SchemaObject(type: 'string');
    }

    private function inferFromPropertyAccess(Node $node, string $resourceClass): SchemaObject
    {
        $propertyName = null;
        if ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
            if ($node->name instanceof Node\Identifier) {
                $propertyName = $node->name->toString();
            }
        }

        if ($propertyName === null) {
            return new SchemaObject(type: 'string');
        }

        $isNullsafe = $node instanceof Expr\NullsafePropertyFetch;

        // Track whether this field is hidden on the model (for warning later)
        $isModelHidden = false;

        // Try Eloquent model-aware type resolution first
        if ($this->modelAnalyzer !== null) {
            $modelClass = $this->modelAnalyzer->getModelForResource($resourceClass);
            if ($modelClass !== null) {
                $isModelHidden = ! $this->modelAnalyzer->shouldExposeProperty($modelClass, $propertyName);

                $modelSchema = $this->modelAnalyzer->getPropertyType($modelClass, $propertyName);
                if ($modelSchema !== null) {
                    if ($isNullsafe) {
                        $modelSchema = clone $modelSchema;
                        $modelSchema->nullable = true;
                    }

                    if ($isModelHidden) {
                        $modelSchema = clone $modelSchema;
                        $modelSchema->description = ($modelSchema->description ? $modelSchema->description.' — ' : '')
                            .'Warning: this field is in the model\'s $hidden array and may not appear in responses';
                    }

                    return $modelSchema;
                }
            }
        }

        // Fall back to common naming patterns
        $schema = match (true) {
            str_ends_with($propertyName, '_id') || $propertyName === 'id' => SchemaObject::integer(),
            str_ends_with($propertyName, '_at') => SchemaObject::string('date-time'),
            str_ends_with($propertyName, '_date') => SchemaObject::string('date'),
            $propertyName === 'email' => SchemaObject::string('email'),
            $propertyName === 'uuid' => SchemaObject::string('uuid'),
            $propertyName === 'url' || str_ends_with($propertyName, '_url') => SchemaObject::string('uri'),
            str_starts_with($propertyName, 'is_') || str_starts_with($propertyName, 'has_') => SchemaObject::boolean(),
            in_array($propertyName, ['price', 'amount', 'total', 'balance', 'cost']) => SchemaObject::number('double'),
            in_array($propertyName, ['count', 'quantity', 'age', 'position', 'order']) => SchemaObject::integer(),
            $isNullsafe => new SchemaObject(type: 'string', nullable: true),
            default => SchemaObject::string(),
        };

        if ($isModelHidden) {
            $schema->description = ($schema->description ? $schema->description.' — ' : '')
                .'Warning: this field is in the model\'s $hidden array and may not appear in responses';
        }

        return $schema;
    }

    private function inferConditionalField(Expr\MethodCall $call, string $resourceClass): SchemaObject
    {
        // $this->when($condition, $value) - infer from the value argument
        if (count($call->args) >= 2) {
            $valueNode = $call->args[1]->value;

            // Resolve closure/arrow function return values
            $returnExpr = $this->getClosureReturnExpression($valueNode);
            if ($returnExpr !== null) {
                $schema = $this->inferPropertySchema($returnExpr, $resourceClass);
                $schema->nullable = true;

                return $schema;
            }

            $schema = $this->inferPropertySchema($valueNode, $resourceClass);
            $schema->nullable = true;

            return $schema;
        }

        // $this->whenLoaded('relation') - it's a relationship
        if (count($call->args) >= 1 && $call->args[0]->value instanceof String_) {
            $relationName = $call->args[0]->value->value;
            $relationSchema = $this->resolveRelationSchema($relationName, $resourceClass);
            if ($relationSchema !== null) {
                $relationSchema->nullable = true;

                return $relationSchema;
            }

            return new SchemaObject(type: 'object', nullable: true);
        }

        return new SchemaObject(type: 'string', nullable: true);
    }

    /**
     * Extract the return expression from a closure or arrow function.
     */
    private function getClosureReturnExpression(Node $node): ?Expr
    {
        // Arrow function: fn() => expr
        if ($node instanceof Expr\ArrowFunction) {
            return $node->expr;
        }

        // Closure: function() { return expr; }
        if ($node instanceof Expr\Closure) {
            foreach ($node->stmts ?? [] as $stmt) {
                if ($stmt instanceof Return_ && $stmt->expr !== null) {
                    return $stmt->expr;
                }
            }
        }

        return null;
    }

    /**
     * Extract properties from a merge()/mergeWhen() call.
     *
     * @return array{properties: array<string, SchemaObject>, required: string[]}|null
     */
    private function extractMergeProperties(Node $value, string $resourceClass): ?array
    {
        if (! $value instanceof Expr\MethodCall || ! $value->name instanceof Node\Identifier) {
            return null;
        }

        $methodName = $value->name->toString();
        if ($methodName !== 'merge' && $methodName !== 'mergeWhen') {
            return null;
        }

        // merge([...]) — array is arg[0]; mergeWhen(cond, [...]) — array is arg[1]
        $arrayArgIndex = $methodName === 'merge' ? 0 : 1;
        if (! isset($value->args[$arrayArgIndex])) {
            return null;
        }

        $arrayNode = $value->args[$arrayArgIndex]->value;

        // Handle closure/arrow function wrapping the array
        $returnExpr = $this->getClosureReturnExpression($arrayNode);
        if ($returnExpr !== null) {
            $arrayNode = $returnExpr;
        }

        if (! $arrayNode instanceof Array_) {
            return null;
        }

        $properties = [];
        $required = [];
        $isMergeWhen = $methodName === 'mergeWhen';

        foreach ($arrayNode->items as $item) {
            if (! $item instanceof ArrayItem || ! $item->key instanceof String_) {
                continue;
            }

            $key = $item->key->value;
            $propSchema = $this->inferPropertySchema($item->value, $resourceClass);

            // mergeWhen properties are conditional — mark as nullable
            if ($isMergeWhen) {
                $propSchema->nullable = true;
            }

            $properties[$key] = $propSchema;

            if (! $isMergeWhen && ! $this->isConditionalField($item->value)) {
                $required[] = $key;
            }
        }

        return ['properties' => $properties, 'required' => $required];
    }

    /**
     * Resolve the schema for a model relationship by introspecting the relation method.
     */
    private function resolveRelationSchema(string $relationName, string $resourceClass): ?SchemaObject
    {
        if ($this->modelAnalyzer === null) {
            return null;
        }

        $modelClass = $this->modelAnalyzer->getModelForResource($resourceClass);
        if ($modelClass === null) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($modelClass);
            if (! $reflection->hasMethod($relationName)) {
                return null;
            }

            $method = $reflection->getMethod($relationName);
            $returnType = $method->getReturnType();

            if (! $returnType instanceof \ReflectionNamedType) {
                return null;
            }

            $returnTypeName = $returnType->getName();

            // Determine if this is a to-many or to-one relation
            $toManyRelations = [
                'Illuminate\Database\Eloquent\Relations\HasMany',
                'Illuminate\Database\Eloquent\Relations\BelongsToMany',
                'Illuminate\Database\Eloquent\Relations\HasManyThrough',
                'Illuminate\Database\Eloquent\Relations\MorphMany',
                'Illuminate\Database\Eloquent\Relations\MorphToMany',
            ];
            $toOneRelations = [
                'Illuminate\Database\Eloquent\Relations\BelongsTo',
                'Illuminate\Database\Eloquent\Relations\HasOne',
                'Illuminate\Database\Eloquent\Relations\HasOneThrough',
                'Illuminate\Database\Eloquent\Relations\MorphOne',
                'Illuminate\Database\Eloquent\Relations\MorphTo',
            ];

            $isToMany = in_array($returnTypeName, $toManyRelations, true);
            $isToOne = in_array($returnTypeName, $toOneRelations, true);

            if (! $isToMany && ! $isToOne) {
                return null;
            }

            // Extract the related model class from the relation's return type template
            $relatedModel = $this->extractRelatedModel($reflection, $relationName);

            if ($relatedModel === null) {
                return $isToMany
                    ? SchemaObject::array(SchemaObject::object())
                    : SchemaObject::object();
            }

            // Try to find a matching Resource for the related model
            $relatedSchema = $this->resolveRelatedModelSchema($relatedModel, $resourceClass);

            if ($relatedSchema === null) {
                $relatedSchema = SchemaObject::object();
            }

            return $isToMany ? SchemaObject::array($relatedSchema) : $relatedSchema;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract the related model class from a relation method by invoking it.
     */
    private function extractRelatedModel(\ReflectionClass $modelReflection, string $relationName): ?string
    {
        try {
            // Parse the relation method body to find the related model class
            $method = $modelReflection->getMethod($relationName);
            $fileName = $method->getFileName();
            if (! $fileName || ! file_exists($fileName)) {
                return null;
            }

            $stmts = $this->astCache->parseFile($fileName);
            if ($stmts === null) {
                return null;
            }

            $nodeFinder = new NodeFinder;
            $methods = $nodeFinder->findInstanceOf($stmts, ClassMethod::class);

            foreach ($methods as $classMethod) {
                if ($classMethod->name->toString() !== $relationName) {
                    continue;
                }

                // Find $this->hasMany(Comment::class) / $this->belongsTo(User::class) etc.
                $methodCalls = $nodeFinder->findInstanceOf($classMethod->stmts ?? [], Expr\MethodCall::class);
                foreach ($methodCalls as $call) {
                    if (! $call->name instanceof Node\Identifier) {
                        continue;
                    }

                    $name = $call->name->toString();
                    $relationMethods = ['hasMany', 'belongsTo', 'hasOne', 'belongsToMany', 'hasManyThrough', 'hasOneThrough', 'morphMany', 'morphOne', 'morphTo', 'morphToMany'];
                    if (! in_array($name, $relationMethods, true)) {
                        continue;
                    }

                    // First argument is the related model class
                    if (isset($call->args[0]) && $call->args[0]->value instanceof Expr\ClassConstFetch) {
                        $classConst = $call->args[0]->value;
                        if ($classConst->class instanceof Node\Name && $classConst->name instanceof Node\Identifier && $classConst->name->toString() === 'class') {
                            $className = $classConst->class->toString();

                            // Resolve use statements
                            $useMap = $this->resolveUseStatements($fileName);
                            if (isset($useMap[$className])) {
                                $fqcn = $useMap[$className];
                                if (class_exists($fqcn)) {
                                    return $fqcn;
                                }
                            }

                            // Try namespace-relative
                            $namespace = $modelReflection->getNamespaceName();
                            if ($namespace) {
                                $fqcn = $namespace.'\\'.$className;
                                if (class_exists($fqcn)) {
                                    return $fqcn;
                                }
                            }

                            if (class_exists($className)) {
                                return $className;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Parsing failed
        }

        return null;
    }

    /**
     * Resolve use statements from a PHP file.
     *
     * @return array<string, string>
     */
    private function resolveUseStatements(string $filePath): array
    {
        try {
            $stmts = $this->astCache->parseFile($filePath);
            if ($stmts === null) {
                return [];
            }

            $map = [];
            foreach ($stmts as $stmt) {
                if ($stmt instanceof \PhpParser\Node\Stmt\Namespace_) {
                    foreach ($stmt->stmts as $nsStmt) {
                        if ($nsStmt instanceof \PhpParser\Node\Stmt\Use_) {
                            foreach ($nsStmt->uses as $use) {
                                $alias = $use->alias?->toString() ?? $use->name->getLast();
                                $map[$alias] = $use->name->toString();
                            }
                        }
                    }
                } elseif ($stmt instanceof \PhpParser\Node\Stmt\Use_) {
                    foreach ($stmt->uses as $use) {
                        $alias = $use->alias?->toString() ?? $use->name->getLast();
                        $map[$alias] = $use->name->toString();
                    }
                }
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Try to find a Resource class for a related model and resolve its schema.
     * Falls back to a basic model-based schema if no resource is found.
     */
    private function resolveRelatedModelSchema(string $relatedModelClass, string $contextResourceClass): ?SchemaObject
    {
        // Convention: Model\Comment → Resources\CommentResource
        $modelBaseName = class_basename($relatedModelClass);
        $resourceNamespace = substr($contextResourceClass, 0, (int) strrpos($contextResourceClass, '\\'));
        $candidateResource = $resourceNamespace.'\\'.$modelBaseName.'Resource';

        if (class_exists($candidateResource) && is_subclass_of($candidateResource, \Illuminate\Http\Resources\Json\JsonResource::class)) {
            return $this->analyze($candidateResource);
        }

        // Try ClassSchemaResolver for the model
        if ($this->classResolver !== null) {
            $schema = $this->classResolver->resolve($relatedModelClass);
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    private function isConditionalField(Node $value): bool
    {
        if ($value instanceof Expr\MethodCall && $value->name instanceof Node\Identifier) {
            $name = $value->name->toString();

            return in_array($name, ['when', 'whenLoaded', 'whenNotNull', 'whenCounted', 'whenPivotLoaded']);
        }

        return false;
    }

    private function isSequentialArray(Array_ $array): bool
    {
        foreach ($array->items as $item) {
            if ($item instanceof ArrayItem && $item->key !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Analyze a Resource class's constructor parameters to infer schema.
     * Used when the Resource doesn't have a custom toArray() method and wraps typed DTOs.
     */
    private function analyzeConstructorParameters(string $resourceClass): ?SchemaObject
    {
        try {
            $reflection = new \ReflectionClass($resourceClass);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return null;
            }

            // Only analyze if the constructor is declared on this class (not inherited from JsonResource)
            if ($constructor->getDeclaringClass()->getName() !== $resourceClass) {
                return null;
            }

            $params = $constructor->getParameters();
            if (empty($params)) {
                return null;
            }

            foreach ($params as $param) {
                $type = $param->getType();
                if (! $type instanceof \ReflectionNamedType) {
                    continue;
                }

                $typeName = $type->getName();
                if (! class_exists($typeName)) {
                    continue;
                }

                // Try to extract public properties from the DTO class
                $dtoSchema = $this->analyzeDtoProperties($typeName);
                if ($dtoSchema !== null) {
                    if ($param->isVariadic()) {
                        return SchemaObject::array($dtoSchema);
                    }

                    return $dtoSchema;
                }
            }
        } catch (\Throwable) {
            // Reflection failed
        }

        return null;
    }

    /**
     * Analyze a DTO/Data class's public properties to build a schema.
     * Handles Spatie Data objects (promoted constructor params) and plain DTOs.
     */
    private function analyzeDtoProperties(string $className): ?SchemaObject
    {
        // Delegate to ClassSchemaResolver for recursive resolution
        if ($this->classResolver !== null) {
            return $this->classResolver->resolve($className);
        }

        try {
            $reflection = new \ReflectionClass($className);
            $properties = [];
            $required = [];

            // Check constructor for promoted properties (Spatie Data, readonly DTOs)
            $constructor = $reflection->getConstructor();
            if ($constructor !== null) {
                foreach ($constructor->getParameters() as $param) {
                    if (! $param->isPromoted()) {
                        continue;
                    }

                    $propReflection = $reflection->getProperty($param->getName());
                    if (! $propReflection->isPublic()) {
                        continue;
                    }

                    $type = $param->getType();
                    $propSchema = $type !== null ? $this->typeMapper->mapReflectionType($type) : SchemaObject::string();
                    $properties[$param->getName()] = $propSchema;

                    if (! $param->isOptional() && ! ($type instanceof \ReflectionNamedType && $type->allowsNull())) {
                        $required[] = $param->getName();
                    }
                }
            }

            // Also check declared public properties (non-promoted)
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                if (isset($properties[$prop->getName()])) {
                    continue; // Already handled via constructor
                }
                if ($prop->getDeclaringClass()->getName() !== $className) {
                    continue; // Skip inherited properties
                }

                $type = $prop->getType();
                $propSchema = $type !== null ? $this->typeMapper->mapReflectionType($type) : SchemaObject::string();
                $properties[$prop->getName()] = $propSchema;
            }

            if (empty($properties)) {
                return null;
            }

            return SchemaObject::object($properties, ! empty($required) ? $required : null);
        } catch (\Throwable) {
            return null;
        }
    }

    private function tryResolveResource(string $className, string $contextClass): ?SchemaObject
    {
        // Try to resolve the full class name
        $fqcn = $className;
        if (! class_exists($fqcn)) {
            $namespace = substr($contextClass, 0, strrpos($contextClass, '\\'));
            $fqcn = $namespace.'\\'.$className;
        }

        if (class_exists($fqcn)) {
            if (is_subclass_of($fqcn, \Illuminate\Http\Resources\Json\JsonResource::class)) {
                return $this->analyze($fqcn);
            }

            // Try ClassSchemaResolver for non-JsonResource classes (DTOs, value objects)
            if ($this->classResolver !== null) {
                return $this->classResolver->resolve($fqcn);
            }
        }

        return null;
    }
}
