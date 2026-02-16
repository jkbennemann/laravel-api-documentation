<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Plugins;

use JkBennemann\LaravelApiDocumentation\Contracts\OperationTransformer;
use JkBennemann\LaravelApiDocumentation\Contracts\Plugin;
use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\PluginRegistry;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Plugin for timacdonald/json-api package.
 *
 * Detects JsonApiResource usage and generates proper JSON:API
 * response schemas with type/id/attributes/relationships/links structure.
 */
class JsonApiPlugin implements OperationTransformer, Plugin, ResponseExtractor
{
    private const JSON_API_RESOURCE_CLASS = 'TiMacDo\\JsonApi\\JsonApiResource';

    private const JSON_API_RESOURCE_COLLECTION_CLASS = 'TiMacDo\\JsonApi\\JsonApiResourceCollection';

    public function name(): string
    {
        return 'json-api';
    }

    public function boot(PluginRegistry $registry): void
    {
        if (! class_exists(self::JSON_API_RESOURCE_CLASS)
            && ! class_exists('TiMacDo\JsonApi\JsonApiResource')) {
            return;
        }

        $registry->addResponseExtractor($this, 85);
        $registry->addOperationTransformer($this, 35);
    }

    public function priority(): int
    {
        return 35;
    }

    public function extract(AnalysisContext $ctx): array
    {
        if ($ctx->reflectionMethod === null) {
            return [];
        }

        $returnType = $ctx->reflectionMethod->getReturnType();
        if (! $returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
            return [];
        }

        $className = $returnType->getName();

        // Check if return type is a JsonApiResource or collection
        if ($this->isJsonApiResource($className)) {
            return [
                new ResponseResult(
                    statusCode: 200,
                    schema: $this->buildJsonApiSingleSchema($className),
                    description: 'JSON:API resource response',
                    contentType: 'application/vnd.api+json',
                    source: 'plugin:json-api',
                ),
            ];
        }

        if ($this->isJsonApiCollection($className)) {
            return [
                new ResponseResult(
                    statusCode: 200,
                    schema: $this->buildJsonApiCollectionSchema($className),
                    description: 'JSON:API resource collection response',
                    contentType: 'application/vnd.api+json',
                    source: 'plugin:json-api',
                    isCollection: true,
                ),
            ];
        }

        return [];
    }

    public function transform(array $operation, AnalysisContext $ctx): array
    {
        // If the response uses JSON:API content type, update Accept header
        foreach ($operation['responses'] ?? [] as $statusCode => $response) {
            if (isset($response['content']['application/vnd.api+json'])) {
                $operation['parameters'] ??= [];

                // Check if Accept header already exists
                $hasAccept = false;
                foreach ($operation['parameters'] as $param) {
                    if (($param['in'] ?? '') === 'header' && ($param['name'] ?? '') === 'Accept') {
                        $hasAccept = true;
                        break;
                    }
                }

                if (! $hasAccept) {
                    $operation['parameters'][] = [
                        'name' => 'Accept',
                        'in' => 'header',
                        'required' => false,
                        'schema' => ['type' => 'string', 'default' => 'application/vnd.api+json'],
                        'description' => 'JSON:API media type',
                    ];
                }
                break;
            }
        }

        return $operation;
    }

    private function isJsonApiResource(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        try {
            $ref = new \ReflectionClass($className);

            return $ref->isSubclassOf(self::JSON_API_RESOURCE_CLASS)
                || $ref->getName() === self::JSON_API_RESOURCE_CLASS;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isJsonApiCollection(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        try {
            $ref = new \ReflectionClass($className);

            return $ref->isSubclassOf(self::JSON_API_RESOURCE_COLLECTION_CLASS)
                || $ref->getName() === self::JSON_API_RESOURCE_COLLECTION_CLASS;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildJsonApiSingleSchema(string $resourceClass): SchemaObject
    {
        $resourceData = $this->buildResourceObjectSchema($resourceClass);

        return SchemaObject::object(
            properties: [
                'data' => $resourceData,
                'included' => SchemaObject::array($this->buildGenericResourceObject()),
                'jsonapi' => $this->buildJsonApiVersionSchema(),
            ],
            required: ['data'],
        );
    }

    private function buildJsonApiCollectionSchema(string $resourceClass): SchemaObject
    {
        $resourceData = $this->buildResourceObjectSchema($resourceClass);

        return SchemaObject::object(
            properties: [
                'data' => SchemaObject::array($resourceData),
                'included' => SchemaObject::array($this->buildGenericResourceObject()),
                'meta' => SchemaObject::object([
                    'current_page' => SchemaObject::integer(),
                    'from' => new SchemaObject(type: 'integer', nullable: true),
                    'last_page' => SchemaObject::integer(),
                    'per_page' => SchemaObject::integer(),
                    'to' => new SchemaObject(type: 'integer', nullable: true),
                    'total' => SchemaObject::integer(),
                ]),
                'links' => SchemaObject::object([
                    'first' => SchemaObject::string('uri'),
                    'last' => new SchemaObject(type: 'string', format: 'uri', nullable: true),
                    'prev' => new SchemaObject(type: 'string', format: 'uri', nullable: true),
                    'next' => new SchemaObject(type: 'string', format: 'uri', nullable: true),
                ]),
                'jsonapi' => $this->buildJsonApiVersionSchema(),
            ],
            required: ['data'],
        );
    }

    private function buildResourceObjectSchema(string $resourceClass): SchemaObject
    {
        $typeName = $this->inferTypeName($resourceClass);
        $attributes = $this->extractAttributes($resourceClass);
        $relationships = $this->extractRelationships($resourceClass);

        $properties = [
            'type' => new SchemaObject(type: 'string', example: $typeName),
            'id' => SchemaObject::string(),
            'attributes' => $attributes,
            'links' => SchemaObject::object([
                'self' => SchemaObject::string('uri'),
            ]),
        ];

        if ($relationships !== null) {
            $properties['relationships'] = $relationships;
        }

        return SchemaObject::object(
            properties: $properties,
            required: ['type', 'id', 'attributes'],
        );
    }

    private function buildGenericResourceObject(): SchemaObject
    {
        return SchemaObject::object(
            properties: [
                'type' => SchemaObject::string(),
                'id' => SchemaObject::string(),
                'attributes' => SchemaObject::object(),
            ],
            required: ['type', 'id'],
        );
    }

    private function buildJsonApiVersionSchema(): SchemaObject
    {
        return SchemaObject::object([
            'version' => new SchemaObject(type: 'string', example: '1.0'),
        ]);
    }

    private function inferTypeName(string $resourceClass): string
    {
        // Try to get type from resource class
        try {
            $ref = new \ReflectionClass($resourceClass);
            if ($ref->hasMethod('toType')) {
                // Some implementations define type via method
                return str_replace('Resource', '', class_basename($resourceClass));
            }
        } catch (\Throwable) {
            // Fall through
        }

        // Derive from class name: UserResource -> users
        $baseName = class_basename($resourceClass);
        $baseName = str_replace(['Resource', 'JsonApi'], '', $baseName);

        return strtolower($baseName).'s';
    }

    private function extractAttributes(string $resourceClass): SchemaObject
    {
        try {
            $ref = new \ReflectionClass($resourceClass);

            // Look for toAttributes method
            if ($ref->hasMethod('toAttributes')) {
                return $this->analyzeToAttributesMethod($ref);
            }
        } catch (\Throwable) {
            // Fall through
        }

        return SchemaObject::object();
    }

    private function extractRelationships(string $resourceClass): ?SchemaObject
    {
        try {
            $ref = new \ReflectionClass($resourceClass);

            if ($ref->hasMethod('toRelationships')) {
                return $this->analyzeToRelationshipsMethod($ref);
            }
        } catch (\Throwable) {
            // Fall through
        }

        return null;
    }

    private function analyzeToAttributesMethod(\ReflectionClass $ref): SchemaObject
    {
        $method = $ref->getMethod('toAttributes');
        $fileName = $ref->getFileName();

        if ($fileName === false) {
            return SchemaObject::object();
        }

        try {
            $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse(file_get_contents($fileName));

            if ($ast === null) {
                return SchemaObject::object();
            }

            $finder = new NodeFinder;
            $methods = $finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);

            foreach ($methods as $classMethod) {
                if ($classMethod->name->toString() !== 'toAttributes') {
                    continue;
                }

                return $this->extractPropertiesFromArrayReturn($classMethod);
            }
        } catch (\Throwable) {
            // Fall through
        }

        return SchemaObject::object();
    }

    private function analyzeToRelationshipsMethod(\ReflectionClass $ref): SchemaObject
    {
        // Relationships follow the JSON:API structure: { "relationName": { "data": { "type": "...", "id": "..." } } }
        $method = $ref->getMethod('toRelationships');
        $fileName = $ref->getFileName();

        if ($fileName === false) {
            return SchemaObject::object();
        }

        try {
            $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
            $ast = $parser->parse(file_get_contents($fileName));

            if ($ast === null) {
                return SchemaObject::object();
            }

            $finder = new NodeFinder;
            $methods = $finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);

            foreach ($methods as $classMethod) {
                if ($classMethod->name->toString() !== 'toRelationships') {
                    continue;
                }

                // Find returned array keys — each is a relationship
                $returns = $finder->findInstanceOf($classMethod, Node\Stmt\Return_::class);
                $properties = [];

                foreach ($returns as $return) {
                    if ($return->expr instanceof Node\Expr\Array_) {
                        foreach ($return->expr->items as $item) {
                            if ($item instanceof Node\ArrayItem
                                && $item->key instanceof Node\Scalar\String_) {
                                $relName = $item->key->value;
                                $properties[$relName] = SchemaObject::object([
                                    'data' => SchemaObject::object(
                                        properties: [
                                            'type' => SchemaObject::string(),
                                            'id' => SchemaObject::string(),
                                        ],
                                        required: ['type', 'id'],
                                    ),
                                ]);
                            }
                        }
                    }
                }

                if (! empty($properties)) {
                    return SchemaObject::object($properties);
                }
            }
        } catch (\Throwable) {
            // Fall through
        }

        return SchemaObject::object();
    }

    private function extractPropertiesFromArrayReturn(Node\Stmt\ClassMethod $method): SchemaObject
    {
        $finder = new NodeFinder;
        $returns = $finder->findInstanceOf($method, Node\Stmt\Return_::class);

        $properties = [];
        foreach ($returns as $return) {
            if ($return->expr instanceof Node\Expr\Array_) {
                foreach ($return->expr->items as $item) {
                    if ($item instanceof Node\ArrayItem
                        && $item->key instanceof Node\Scalar\String_) {
                        $properties[$item->key->value] = $this->inferTypeFromExpression($item->value);
                    }
                }
            }
        }

        return SchemaObject::object($properties);
    }

    private function inferTypeFromExpression(Node\Expr $expr): SchemaObject
    {
        // Property fetch: $this->model->property
        if ($expr instanceof Node\Expr\PropertyFetch
            || $expr instanceof Node\Expr\NullsafePropertyFetch) {
            return SchemaObject::string();
        }

        if ($expr instanceof Node\Scalar\String_) {
            return SchemaObject::string();
        }

        if ($expr instanceof Node\Scalar\Int_) {
            return SchemaObject::integer();
        }

        if ($expr instanceof Node\Scalar\Float_) {
            return SchemaObject::number();
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            $name = $expr->name->toString();
            if (in_array($name, ['true', 'false'], true)) {
                return SchemaObject::boolean();
            }
        }

        return SchemaObject::string();
    }
}
