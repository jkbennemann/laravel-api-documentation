<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

final class AnalysisContext
{
    /**
     * @param  array<string, mixed>  $attributes  Parsed PHP 8 attributes
     * @param  array<string, mixed>  $metadata  Extra metadata from discovery phase
     */
    public function __construct(
        public readonly RouteInfo $route,
        public readonly ?ReflectionMethod $reflectionMethod = null,
        public readonly ClassMethod|Closure|null $astNode = null,
        public readonly ?string $sourceFilePath = null,
        public readonly array $attributes = [],
        public readonly array $metadata = [],
        public readonly ?ReflectionFunction $reflectionFunction = null,
    ) {}

    public function controllerClass(): ?string
    {
        return $this->route->controllerClass();
    }

    public function actionMethod(): ?string
    {
        return $this->route->actionMethod();
    }

    public function hasReflection(): bool
    {
        return $this->reflectionMethod !== null || $this->reflectionFunction !== null;
    }

    /**
     * Get the reflection callable (method or function), whichever is available.
     */
    public function reflectionCallable(): ?ReflectionFunctionAbstract
    {
        return $this->reflectionMethod ?? $this->reflectionFunction;
    }

    public function hasAst(): bool
    {
        return $this->astNode !== null;
    }

    /**
     * Get a specific attribute instance by class name.
     *
     * @template T
     *
     * @param  class-string<T>  $attributeClass
     * @return T|null
     */
    public function getAttribute(string $attributeClass): mixed
    {
        return $this->attributes[$attributeClass] ?? null;
    }

    /**
     * Get all instances of a repeatable attribute.
     *
     * @template T
     *
     * @param  class-string<T>  $attributeClass
     * @return T[]
     */
    public function getAttributes(string $attributeClass): array
    {
        $attr = $this->attributes[$attributeClass] ?? null;
        if ($attr === null) {
            return [];
        }

        return is_array($attr) ? $attr : [$attr];
    }

    public function hasAttribute(string $attributeClass): bool
    {
        return isset($this->attributes[$attributeClass]);
    }

    public function withMetadata(string $key, mixed $value): self
    {
        $metadata = $this->metadata;
        $metadata[$key] = $value;

        return new self(
            route: $this->route,
            reflectionMethod: $this->reflectionMethod,
            astNode: $this->astNode,
            sourceFilePath: $this->sourceFilePath,
            attributes: $this->attributes,
            metadata: $metadata,
            reflectionFunction: $this->reflectionFunction,
        );
    }
}
