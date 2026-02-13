<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

it('serializes nullable type as array with null', function () {
    $schema = new SchemaObject(type: 'string', nullable: true);
    $data = $schema->jsonSerialize();

    expect($data['type'])->toBe(['string', 'null']);
});

it('serializes non-nullable type as string', function () {
    $schema = new SchemaObject(type: 'string');
    $data = $schema->jsonSerialize();

    expect($data['type'])->toBe('string');
});

it('adds null type to oneOf when nullable and no base type', function () {
    $schema = new SchemaObject(
        oneOf: [
            new SchemaObject(type: 'string'),
            new SchemaObject(type: 'integer'),
        ],
        nullable: true,
    );

    $data = $schema->jsonSerialize();

    expect($data)->toHaveKey('oneOf')
        ->and($data['oneOf'])->toHaveCount(3)
        ->and($data['oneOf'][2])->toBe(['type' => 'null']);
});

it('does not add null type to oneOf when not nullable', function () {
    $schema = new SchemaObject(
        oneOf: [
            new SchemaObject(type: 'string'),
            new SchemaObject(type: 'integer'),
        ],
    );

    $data = $schema->jsonSerialize();

    expect($data['oneOf'])->toHaveCount(2);
});

it('adds null type to anyOf when nullable and no base type', function () {
    $schema = new SchemaObject(
        anyOf: [
            new SchemaObject(type: 'string'),
            new SchemaObject(type: 'integer'),
        ],
        nullable: true,
    );

    $data = $schema->jsonSerialize();

    expect($data)->toHaveKey('anyOf')
        ->and($data['anyOf'])->toHaveCount(3)
        ->and($data['anyOf'][2])->toBe(['type' => 'null']);
});

it('does not add null to oneOf when type is already set', function () {
    $schema = new SchemaObject(
        type: 'object',
        oneOf: [
            new SchemaObject(type: 'string'),
        ],
        nullable: true,
    );

    $data = $schema->jsonSerialize();

    // type is set, so nullable is expressed via type array, not oneOf addition
    expect($data['type'])->toBe(['object', 'null'])
        ->and($data['oneOf'])->toHaveCount(1);
});

it('cloning prevents mutation leaking between schemas', function () {
    $original = new SchemaObject(type: 'string');
    $cloned = clone $original;
    $cloned->nullable = true;

    expect($original->nullable)->toBeFalse()
        ->and($cloned->nullable)->toBeTrue();
});

it('serializes object with properties', function () {
    $schema = SchemaObject::object(
        properties: [
            'name' => new SchemaObject(type: 'string'),
            'age' => new SchemaObject(type: 'integer'),
        ],
        required: ['name'],
    );

    $data = $schema->jsonSerialize();

    expect($data['type'])->toBe('object')
        ->and($data['properties'])->toHaveCount(2)
        ->and($data['required'])->toBe(['name']);
});

it('serializes array with items', function () {
    $schema = SchemaObject::array(new SchemaObject(type: 'string'));
    $data = $schema->jsonSerialize();

    expect($data['type'])->toBe('array')
        ->and($data['items']['type'])->toBe('string');
});

it('serializes empty array as [] and empty object as {}', function () {
    $arraySchema = new SchemaObject(type: 'array');
    $objectSchema = new SchemaObject(type: 'object');

    expect($arraySchema->jsonSerialize()['type'])->toBe('array')
        ->and($objectSchema->jsonSerialize()['type'])->toBe('object');
});

// --- OpenAPI 3.0 compatibility ---

it('uses nullable property instead of type array for openapi 3.0', function () {
    $original = SchemaObject::$openApiVersion;
    SchemaObject::$openApiVersion = '3.0.2';

    try {
        $schema = new SchemaObject(type: 'string', nullable: true);
        $data = $schema->jsonSerialize();

        expect($data['type'])->toBe('string')
            ->and($data['nullable'])->toBeTrue();
    } finally {
        SchemaObject::$openApiVersion = $original;
    }
});

it('uses nullable property for oneOf in openapi 3.0', function () {
    $original = SchemaObject::$openApiVersion;
    SchemaObject::$openApiVersion = '3.0.2';

    try {
        $schema = new SchemaObject(
            oneOf: [
                new SchemaObject(type: 'string'),
                new SchemaObject(type: 'integer'),
            ],
            nullable: true,
        );
        $data = $schema->jsonSerialize();

        expect($data['oneOf'])->toHaveCount(2)
            ->and($data['nullable'])->toBeTrue();
    } finally {
        SchemaObject::$openApiVersion = $original;
    }
});

it('uses nullable property for anyOf in openapi 3.0', function () {
    $original = SchemaObject::$openApiVersion;
    SchemaObject::$openApiVersion = '3.0.2';

    try {
        $schema = new SchemaObject(
            anyOf: [
                new SchemaObject(type: 'string'),
                new SchemaObject(type: 'integer'),
            ],
            nullable: true,
        );
        $data = $schema->jsonSerialize();

        expect($data['anyOf'])->toHaveCount(2)
            ->and($data['nullable'])->toBeTrue();
    } finally {
        SchemaObject::$openApiVersion = $original;
    }
});

// --- Type alias normalization ---

it('normalizes type aliases to string + format', function (string $inputType, string $expectedType, string $expectedFormat) {
    $schema = new SchemaObject(type: $inputType);
    $data = $schema->jsonSerialize();

    expect($data['type'])->toBe($expectedType)
        ->and($data['format'])->toBe($expectedFormat);
})->with([
    'date' => ['date', 'string', 'date'],
    'datetime' => ['datetime', 'string', 'date-time'],
    'date-time' => ['date-time', 'string', 'date-time'],
    'time' => ['time', 'string', 'time'],
    'timestamp' => ['timestamp', 'string', 'date-time'],
    'email' => ['email', 'string', 'email'],
    'url' => ['url', 'string', 'uri'],
    'uri' => ['uri', 'string', 'uri'],
    'uuid' => ['uuid', 'string', 'uuid'],
    'ip' => ['ip', 'string', 'ipv4'],
    'ipv4' => ['ipv4', 'string', 'ipv4'],
    'ipv6' => ['ipv6', 'string', 'ipv6'],
    'binary' => ['binary', 'string', 'binary'],
    'byte' => ['byte', 'string', 'byte'],
    'password' => ['password', 'string', 'password'],
]);

it('preserves user-provided format over inferred format', function () {
    $schema = new SchemaObject(type: 'date', format: 'custom-date');
    $data = $schema->jsonSerialize();

    expect($data['type'])->toBe('string')
        ->and($data['format'])->toBe('custom-date');
});

it('passes standard OpenAPI types through unchanged', function (string $type) {
    $schema = new SchemaObject(type: $type);
    $data = $schema->jsonSerialize();

    expect($data['type'])->toBe($type)
        ->and($data)->not->toHaveKey('format');
})->with(['string', 'integer', 'number', 'boolean', 'object', 'array']);

it('normalizes type alias with nullable', function () {
    $schema = new SchemaObject(type: 'email', nullable: true);
    $data = $schema->jsonSerialize();

    expect($data['type'])->toBe(['string', 'null'])
        ->and($data['format'])->toBe('email');
});
