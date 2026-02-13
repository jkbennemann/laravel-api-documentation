<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Data;

final class SchemaObject implements \JsonSerializable
{
    /**
     * OpenAPI version to use for serialization.
     * Set before emitting to control nullable syntax:
     * - 3.1.x: type: ["string", "null"]
     * - 3.0.x: type: "string", nullable: true
     */
    public static string $openApiVersion = '3.1.0';

    /**
     * @param  array<string, SchemaObject>|null  $properties
     * @param  string[]|null  $required
     * @param  array<mixed>|null  $enum
     * @param  SchemaObject[]|null  $oneOf
     * @param  SchemaObject[]|null  $allOf
     * @param  SchemaObject[]|null  $anyOf
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public ?string $type = null,
        public ?string $format = null,
        public ?SchemaObject $items = null,
        public ?array $properties = null,
        public ?array $required = null,
        public ?string $description = null,
        public mixed $example = null,
        public mixed $default = null,
        public ?array $enum = null,
        public bool $nullable = false,
        public ?string $pattern = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?array $oneOf = null,
        public ?array $allOf = null,
        public ?array $anyOf = null,
        public ?Reference $ref = null,
        public bool $deprecated = false,
        public ?string $title = null,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public array $extra = [],
    ) {}

    public static function string(?string $format = null, ?string $description = null): self
    {
        return new self(type: 'string', format: $format, description: $description);
    }

    public static function integer(?string $format = null): self
    {
        return new self(type: 'integer', format: $format);
    }

    public static function number(?string $format = null): self
    {
        return new self(type: 'number', format: $format);
    }

    public static function boolean(): self
    {
        return new self(type: 'boolean');
    }

    public static function array(SchemaObject $items): self
    {
        return new self(type: 'array', items: $items);
    }

    /**
     * @param  array<string, SchemaObject>  $properties
     * @param  string[]|null  $required
     */
    public static function object(array $properties = [], ?array $required = null): self
    {
        return new self(type: 'object', properties: $properties, required: $required);
    }

    public static function ref(Reference $ref): self
    {
        return new self(ref: $ref);
    }

    public function withProperty(string $name, SchemaObject $schema): self
    {
        $clone = clone $this;
        $clone->properties ??= [];
        $clone->properties[$name] = $schema;

        return $clone;
    }

    public function withRequired(string ...$fields): self
    {
        $clone = clone $this;
        $clone->required = array_values(array_unique(
            array_merge($clone->required ?? [], $fields)
        ));

        return $clone;
    }

    public function jsonSerialize(): mixed
    {
        if ($this->ref !== null) {
            return $this->ref->jsonSerialize();
        }

        $data = [];

        $useOpenApi31 = version_compare(self::$openApiVersion, '3.1.0', '>=');

        // Phase 1: Normalize PHP type shorthands to OpenAPI types
        $type = match ($this->type) {
            'int' => 'integer',
            'bool' => 'boolean',
            'float', 'double' => 'number',
            default => $this->type,
        };

        // Phase 2: Type aliases → string + format
        $format = $this->format;
        [$type, $inferredFormat] = match ($type) {
            'date' => ['string', 'date'],
            'datetime', 'date-time' => ['string', 'date-time'],
            'time' => ['string', 'time'],
            'timestamp' => ['string', 'date-time'],
            'email' => ['string', 'email'],
            'url', 'uri' => ['string', 'uri'],
            'uuid' => ['string', 'uuid'],
            'ip', 'ipv4' => ['string', 'ipv4'],
            'ipv6' => ['string', 'ipv6'],
            'binary' => ['string', 'binary'],
            'byte' => ['string', 'byte'],
            'password' => ['string', 'password'],
            default => [$type, null],
        };
        $format = $format ?? $inferredFormat;

        if ($type !== null) {
            if ($this->nullable && $useOpenApi31) {
                $data['type'] = [$type, 'null'];
            } else {
                $data['type'] = $type;
                if ($this->nullable && ! $useOpenApi31) {
                    $data['nullable'] = true;
                }
            }
        }

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($format !== null) {
            $data['format'] = $format;
        }
        if ($this->enum !== null) {
            $data['enum'] = $this->enum;
        }
        if ($this->default !== null) {
            $data['default'] = $this->default;
        }
        if ($this->example !== null) {
            $data['example'] = $this->example;
        }
        if ($this->pattern !== null) {
            $data['pattern'] = $this->pattern;
        }
        if ($this->minLength !== null) {
            $data['minLength'] = $this->minLength;
        }
        if ($this->maxLength !== null) {
            $data['maxLength'] = $this->maxLength;
        }
        if ($this->minimum !== null) {
            $data['minimum'] = $this->minimum;
        }
        if ($this->maximum !== null) {
            $data['maximum'] = $this->maximum;
        }
        if ($this->minItems !== null) {
            $data['minItems'] = $this->minItems;
        }
        if ($this->maxItems !== null) {
            $data['maxItems'] = $this->maxItems;
        }
        if ($this->items !== null) {
            $data['items'] = $this->items->jsonSerialize();
        } elseif ($type === 'array') {
            // Ensure all array types have items (OpenAPI best practice)
            $data['items'] = ['type' => 'string'];
        }
        if ($this->properties !== null && $this->properties !== []) {
            $data['properties'] = array_map(
                fn (SchemaObject $s) => $s->jsonSerialize(),
                $this->properties
            );
        }
        if ($this->required !== null && $this->required !== []) {
            $data['required'] = $this->required;
        }
        if ($this->oneOf !== null) {
            $oneOf = array_map(fn (SchemaObject $s) => $s->jsonSerialize(), $this->oneOf);
            if ($this->nullable && $this->type === null) {
                if ($useOpenApi31) {
                    $oneOf[] = ['type' => 'null'];
                } else {
                    $data['nullable'] = true;
                }
            }
            $data['oneOf'] = $oneOf;
        }
        if ($this->allOf !== null) {
            $data['allOf'] = array_map(fn (SchemaObject $s) => $s->jsonSerialize(), $this->allOf);
        }
        if ($this->anyOf !== null) {
            $anyOf = array_map(fn (SchemaObject $s) => $s->jsonSerialize(), $this->anyOf);
            if ($this->nullable && $this->type === null) {
                if ($useOpenApi31) {
                    $anyOf[] = ['type' => 'null'];
                } else {
                    $data['nullable'] = true;
                }
            }
            $data['anyOf'] = $anyOf;
        }
        if ($this->deprecated) {
            $data['deprecated'] = true;
        }
        if ($this->readOnly) {
            $data['readOnly'] = true;
        }
        if ($this->writeOnly) {
            $data['writeOnly'] = true;
        }

        return array_merge($data, $this->extra);
    }
}
