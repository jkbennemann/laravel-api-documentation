<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Schema;

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

class PhpDocParser
{
    private Lexer $lexer;

    private \PHPStan\PhpDocParser\Parser\PhpDocParser $parser;

    private ?ClassSchemaResolver $classResolver = null;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new \PHPStan\PhpDocParser\Parser\PhpDocParser($config, $typeParser, $constExprParser);
    }

    public function setClassResolver(ClassSchemaResolver $resolver): void
    {
        $this->classResolver = $resolver;
    }

    /**
     * Extract the free-text description from a method's PHPDoc block.
     * Returns the text before any @tags, trimmed.
     */
    public function getDescription(\ReflectionMethod $method): ?string
    {
        $docComment = $method->getDocComment();
        if ($docComment === false || $docComment === '') {
            return null;
        }

        try {
            $phpDoc = $this->parse($docComment);

            $lines = [];
            foreach ($phpDoc->children as $child) {
                if (! $child instanceof PhpDocTextNode) {
                    break;
                }
                $text = trim($child->text);
                if ($text !== '') {
                    $lines[] = $text;
                }
            }

            $description = implode(' ', $lines);

            return $description !== '' ? $description : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse the @return type from a method's PHPDoc and convert to SchemaObject.
     */
    public function getReturnType(\ReflectionMethod $method): ?SchemaObject
    {
        $docComment = $method->getDocComment();
        if ($docComment === false || $docComment === '') {
            return null;
        }

        try {
            $phpDoc = $this->parse($docComment);
            $returnTags = $phpDoc->getReturnTagValues();

            if (empty($returnTags)) {
                return null;
            }

            /** @var ReturnTagValueNode $returnTag */
            $returnTag = $returnTags[0];

            return $this->mapTypeNode($returnTag->type, $method->getDeclaringClass()->getNamespaceName());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract @throws exception class names from a method's PHPDoc.
     *
     * @return string[]
     */
    public function getThrows(\ReflectionMethod $method): array
    {
        $docComment = $method->getDocComment();
        if ($docComment === false || $docComment === '') {
            return [];
        }

        try {
            $phpDoc = $this->parse($docComment);
            $throwsTags = $phpDoc->getThrowsTagValues();

            $classes = [];
            /** @var ThrowsTagValueNode $tag */
            foreach ($throwsTags as $tag) {
                if ($tag->type instanceof IdentifierTypeNode) {
                    $classes[] = $tag->type->name;
                }
                if ($tag->type instanceof UnionTypeNode) {
                    foreach ($tag->type->types as $type) {
                        if ($type instanceof IdentifierTypeNode) {
                            $classes[] = $type->name;
                        }
                    }
                }
            }

            return $classes;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Parse a @var type from a property's PHPDoc.
     */
    public function getVarType(\ReflectionProperty $prop): ?SchemaObject
    {
        $docComment = $prop->getDocComment();
        if ($docComment === false || $docComment === '') {
            return null;
        }

        try {
            $phpDoc = $this->parse($docComment);
            $varTags = $phpDoc->getVarTagValues();

            if (empty($varTags)) {
                return null;
            }

            /** @var VarTagValueNode $varTag */
            $varTag = $varTags[0];

            return $this->mapTypeNode($varTag->type, $prop->getDeclaringClass()->getNamespaceName());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get class-level @property tags.
     *
     * @return array<string, SchemaObject>
     */
    public function getClassProperties(\ReflectionClass $class): array
    {
        $docComment = $class->getDocComment();
        if ($docComment === false || $docComment === '') {
            return [];
        }

        try {
            $phpDoc = $this->parse($docComment);
            $properties = [];
            $namespace = $class->getNamespaceName();

            foreach ($phpDoc->getPropertyTagValues() as $propTag) {
                $name = ltrim($propTag->propertyName, '$');
                $schema = $this->mapTypeNode($propTag->type, $namespace);
                if ($schema !== null) {
                    $properties[$name] = $schema;
                }
            }

            // Also check @property-read tags
            foreach ($phpDoc->getPropertyReadTagValues() as $propTag) {
                $name = ltrim($propTag->propertyName, '$');
                if (! isset($properties[$name])) {
                    $schema = $this->mapTypeNode($propTag->type, $namespace);
                    if ($schema !== null) {
                        $properties[$name] = $schema;
                    }
                }
            }

            return $properties;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get the return type class name from a @return tag (e.g., @return UserResource).
     */
    public function getReturnClassName(\ReflectionMethod $method): ?string
    {
        $docComment = $method->getDocComment();
        if ($docComment === false || $docComment === '') {
            return null;
        }

        try {
            $phpDoc = $this->parse($docComment);
            $returnTags = $phpDoc->getReturnTagValues();

            if (empty($returnTags)) {
                return null;
            }

            $type = $returnTags[0]->type;

            if ($type instanceof IdentifierTypeNode) {
                $name = $type->name;
                // Filter out scalar types
                if (in_array(strtolower($name), ['string', 'int', 'integer', 'float', 'bool', 'boolean', 'array', 'void', 'null', 'mixed', 'object', 'self', 'static'], true)) {
                    return null;
                }

                return $name;
            }

            // Generic like Collection<UserData>
            if ($type instanceof GenericTypeNode && $type->type instanceof IdentifierTypeNode) {
                $name = $type->type->name;
                if (! in_array(strtolower($name), ['string', 'int', 'integer', 'float', 'bool', 'boolean', 'array', 'void', 'null', 'mixed', 'object'], true)) {
                    return $name;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if a method's PHPDoc contains an @deprecated tag.
     */
    public function isDeprecated(\ReflectionMethod $method): bool
    {
        return $this->isDeprecatedCallable($method);
    }

    /**
     * Check if a callable (method or function) PHPDoc contains an @deprecated tag.
     */
    public function isDeprecatedCallable(\ReflectionFunctionAbstract $callable): bool
    {
        $docComment = $callable->getDocComment();
        if ($docComment === false || $docComment === '') {
            return false;
        }

        try {
            $phpDoc = $this->parse($docComment);

            return count($phpDoc->getDeprecatedTagValues()) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if a class's PHPDoc contains an @deprecated tag.
     */
    public function isClassDeprecated(\ReflectionClass $class): bool
    {
        $docComment = $class->getDocComment();
        if ($docComment === false || $docComment === '') {
            return false;
        }

        try {
            $phpDoc = $this->parse($docComment);

            return count($phpDoc->getDeprecatedTagValues()) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Extract the message text from a @deprecated tag.
     */
    public function getDeprecationMessage(\ReflectionFunctionAbstract|\ReflectionClass $reflector): ?string
    {
        $docComment = $reflector->getDocComment();
        if ($docComment === false || $docComment === '') {
            return null;
        }

        try {
            $phpDoc = $this->parse($docComment);
            $tags = $phpDoc->getDeprecatedTagValues();

            if (empty($tags)) {
                return null;
            }

            $description = trim($tags[0]->description);

            return $description !== '' ? $description : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract @param tags from a method's PHPDoc.
     *
     * @return array<string, array{schema: SchemaObject, description: ?string}>
     */
    public function getParamTags(\ReflectionMethod $method): array
    {
        $docComment = $method->getDocComment();
        if ($docComment === false || $docComment === '') {
            return [];
        }

        try {
            $phpDoc = $this->parse($docComment);
            $paramTags = $phpDoc->getParamTagValues();

            $params = [];
            $namespace = $method->getDeclaringClass()->getNamespaceName();

            foreach ($paramTags as $tag) {
                $name = ltrim($tag->parameterName, '$');
                $schema = $this->mapTypeNode($tag->type, $namespace);
                if ($schema !== null) {
                    $description = trim($tag->description);
                    $params[$name] = [
                        'schema' => $schema,
                        'description' => $description !== '' ? $description : null,
                    ];
                }
            }

            return $params;
        } catch (\Throwable) {
            return [];
        }
    }

    private function parse(string $docComment): PhpDocNode
    {
        $tokens = new TokenIterator($this->lexer->tokenize($docComment));

        return $this->parser->parse($tokens);
    }

    private function mapTypeNode(TypeNode $type, string $namespace = ''): ?SchemaObject
    {
        if ($type instanceof IdentifierTypeNode) {
            return $this->mapIdentifier($type->name, $namespace);
        }

        if ($type instanceof NullableTypeNode) {
            $inner = $this->mapTypeNode($type->type, $namespace);
            if ($inner !== null) {
                $inner = clone $inner;
                $inner->nullable = true;
            }

            return $inner;
        }

        if ($type instanceof UnionTypeNode) {
            $hasNull = false;
            $schemas = [];

            foreach ($type->types as $t) {
                if ($t instanceof IdentifierTypeNode && strtolower($t->name) === 'null') {
                    $hasNull = true;

                    continue;
                }
                $s = $this->mapTypeNode($t, $namespace);
                if ($s !== null) {
                    $schemas[] = $s;
                }
            }

            if (count($schemas) === 1) {
                if ($hasNull) {
                    $schemas[0] = clone $schemas[0];
                    $schemas[0]->nullable = true;
                }

                return $schemas[0];
            }

            if (count($schemas) > 1) {
                return new SchemaObject(oneOf: $schemas, nullable: $hasNull);
            }

            return null;
        }

        if ($type instanceof ArrayTypeNode) {
            $inner = $this->mapTypeNode($type->type, $namespace);

            return SchemaObject::array($inner ?? SchemaObject::string());
        }

        if ($type instanceof GenericTypeNode) {
            return $this->mapGenericType($type, $namespace);
        }

        if ($type instanceof ArrayShapeNode) {
            return $this->mapArrayShape($type, $namespace);
        }

        return null;
    }

    private function mapIdentifier(string $name, string $namespace): ?SchemaObject
    {
        return match (strtolower($name)) {
            'string' => SchemaObject::string(),
            'int', 'integer', 'positive-int', 'negative-int', 'non-positive-int', 'non-negative-int' => SchemaObject::integer(),
            'float', 'double' => SchemaObject::number('double'),
            'bool', 'boolean', 'true', 'false' => SchemaObject::boolean(),
            'null' => new SchemaObject(type: 'string', nullable: true),
            'array', 'list' => new SchemaObject(type: 'array', items: SchemaObject::string()),
            'object', 'stdclass' => SchemaObject::object(),
            'mixed' => new SchemaObject,
            'void', 'never' => null,
            'self', 'static', '$this' => null,
            'scalar' => SchemaObject::string(),
            'numeric', 'numeric-string' => SchemaObject::string(),
            default => $this->mapClassIdentifier($name, $namespace),
        };
    }

    private function mapClassIdentifier(string $name, string $namespace): ?SchemaObject
    {
        // Try fully qualified first
        if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
            return $this->classResolver?->resolve($name);
        }

        // Try with namespace
        if ($namespace !== '') {
            $fqcn = $namespace.'\\'.$name;
            if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
                return $this->classResolver?->resolve($fqcn);
            }
        }

        return null;
    }

    private function mapGenericType(GenericTypeNode $type, string $namespace): ?SchemaObject
    {
        if (! $type->type instanceof IdentifierTypeNode) {
            return null;
        }

        $baseName = strtolower($type->type->name);

        // Collection<Key, Value> or Collection<Value>
        if (in_array($baseName, ['collection', 'illuminate\\support\\collection', 'eloquentcollection', 'lazyCollection', 'array', 'list', 'iterable'], true)
            || str_contains($type->type->name, 'Collection')
        ) {
            $valueType = match (count($type->genericTypes)) {
                1 => $type->genericTypes[0],
                2 => $type->genericTypes[1], // Second generic is the value type
                default => null,
            };

            if ($valueType !== null) {
                $inner = $this->mapTypeNode($valueType, $namespace);

                return SchemaObject::array($inner ?? SchemaObject::object());
            }

            return SchemaObject::array(SchemaObject::object());
        }

        // Paginator<T>, LengthAwarePaginator<T>
        if (str_contains($baseName, 'paginator') || str_contains($type->type->name, 'Paginator')) {
            if (! empty($type->genericTypes)) {
                $inner = $this->mapTypeNode($type->genericTypes[0], $namespace);

                return SchemaObject::array($inner ?? SchemaObject::object());
            }
        }

        // DataCollection<Key, DataClass>
        if (str_contains($type->type->name, 'DataCollection')) {
            $valueType = match (count($type->genericTypes)) {
                1 => $type->genericTypes[0],
                2 => $type->genericTypes[1],
                default => null,
            };

            if ($valueType !== null) {
                $inner = $this->mapTypeNode($valueType, $namespace);

                return SchemaObject::array($inner ?? SchemaObject::object());
            }
        }

        // For other generic types, try resolving the base class
        return $this->mapClassIdentifier($type->type->name, $namespace);
    }

    private function mapArrayShape(ArrayShapeNode $shape, string $namespace): SchemaObject
    {
        $properties = [];
        $required = [];

        foreach ($shape->items as $item) {
            $key = $item->keyName;
            if ($key === null) {
                continue;
            }

            // Key can be ConstExprStringNode or IdentifierTypeNode
            $keyName = (string) $key;
            // Remove surrounding quotes if present
            $keyName = trim($keyName, "'\"");

            $propSchema = $this->mapTypeNode($item->valueType, $namespace);
            if ($propSchema !== null) {
                $properties[$keyName] = $propSchema;
                if (! $item->optional) {
                    $required[] = $keyName;
                }
            }
        }

        return SchemaObject::object($properties, ! empty($required) ? $required : null);
    }
}
