<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Schema;

use JkBennemann\LaravelApiDocumentation\Data\Components;
use JkBennemann\LaravelApiDocumentation\Data\Reference;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class SchemaRegistry
{
    private Components $components;

    private SchemaFingerprinter $fingerprinter;

    /** @var array<string, string> fingerprint → schema name mapping for deduplication */
    private array $fingerprints = [];

    public function __construct()
    {
        $this->components = new Components;
        $this->fingerprinter = new SchemaFingerprinter;
    }

    /**
     * Register a schema by name. If a schema with the same structure
     * already exists, returns a reference to the existing one.
     */
    public function register(string $name, SchemaObject $schema): Reference
    {
        $fingerprint = $this->fingerprinter->fingerprint($schema);

        // Check for duplicate by content
        if (isset($this->fingerprints[$fingerprint])) {
            $existingName = $this->fingerprints[$fingerprint];

            return Reference::schema($existingName);
        }

        // Deduplicate name collisions (same name, different content)
        $registeredName = $name;
        $counter = 1;
        while ($this->components->hasSchema($registeredName)) {
            $existingFingerprint = $this->fingerprinter->fingerprint(
                $this->components->getSchema($registeredName)
            );
            if ($existingFingerprint === $fingerprint) {
                // Same content, reuse
                $this->fingerprints[$fingerprint] = $registeredName;

                return Reference::schema($registeredName);
            }
            $registeredName = $name.$counter;
            $counter++;
        }

        $this->fingerprints[$fingerprint] = $registeredName;

        return $this->components->addSchema($registeredName, $schema);
    }

    /**
     * Register a schema only if it looks complex enough to warrant a $ref.
     * Simple types (string, integer, boolean) are returned inline.
     */
    public function registerIfComplex(string $name, SchemaObject $schema): SchemaObject|Reference
    {
        // Don't create $ref for simple types
        if ($this->isSimpleType($schema)) {
            return $schema;
        }

        $ref = $this->register($name, $schema);

        return SchemaObject::ref($ref);
    }

    public function resolve(Reference $ref): ?SchemaObject
    {
        $name = $ref->name();

        return $this->components->getSchema($name);
    }

    public function getComponents(): Components
    {
        return $this->components;
    }

    public function addSecurityScheme(string $name, array $scheme): void
    {
        $this->components->addSecurityScheme($name, $scheme);
    }

    public function hasSchema(string $name): bool
    {
        return $this->components->hasSchema($name);
    }

    public function reset(): void
    {
        $this->components = new Components;
        $this->fingerprints = [];
    }

    private function isSimpleType(SchemaObject $schema): bool
    {
        if ($schema->ref !== null) {
            return true; // Already a ref
        }

        $simpleTypes = ['string', 'integer', 'number', 'boolean'];

        return in_array($schema->type, $simpleTypes, true)
            && $schema->properties === null
            && $schema->items === null
            && $schema->oneOf === null
            && $schema->allOf === null
            && $schema->anyOf === null;
    }
}
