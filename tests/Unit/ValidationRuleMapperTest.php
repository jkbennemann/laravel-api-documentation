<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;

beforeEach(function () {
    $this->mapper = new ValidationRuleMapper;
});

it('maps string rule to string type', function () {
    $schema = $this->mapper->mapRules(['string']);

    expect($schema->type)->toBe('string');
});

it('maps integer rule to integer type', function () {
    $schema = $this->mapper->mapRules(['integer']);

    expect($schema->type)->toBe('integer');
});

it('maps email rule to string with email format', function () {
    $schema = $this->mapper->mapRules(['email']);

    expect($schema->type)->toBe('string')
        ->and($schema->format)->toBe('email');
});

it('maps uuid rule to string with uuid format', function () {
    $schema = $this->mapper->mapRules(['uuid']);

    expect($schema->type)->toBe('string')
        ->and($schema->format)->toBe('uuid');
});

it('applies min constraint as minLength for strings', function () {
    $schema = $this->mapper->mapRules(['string', 'min:3']);

    expect($schema->type)->toBe('string')
        ->and($schema->minLength)->toBe(3);
});

it('applies max constraint as maxLength for strings', function () {
    $schema = $this->mapper->mapRules(['string', 'max:255']);

    expect($schema->type)->toBe('string')
        ->and($schema->maxLength)->toBe(255);
});

it('applies min constraint as minimum for integers', function () {
    $schema = $this->mapper->mapRules(['integer', 'min:1']);

    expect($schema->type)->toBe('integer')
        ->and($schema->minimum)->toBe(1);
});

it('applies max constraint as maximum for integers', function () {
    $schema = $this->mapper->mapRules(['integer', 'max:100']);

    expect($schema->type)->toBe('integer')
        ->and($schema->maximum)->toBe(100);
});

it('applies min constraint as minItems for arrays', function () {
    $schema = $this->mapper->mapRules(['array', 'min:1']);

    expect($schema->type)->toBe('array')
        ->and($schema->minItems)->toBe(1);
});

it('applies max constraint as maxItems for arrays', function () {
    $schema = $this->mapper->mapRules(['array', 'max:10']);

    expect($schema->type)->toBe('array')
        ->and($schema->maxItems)->toBe(10);
});

it('applies between constraint for numbers', function () {
    $schema = $this->mapper->mapRules(['numeric', 'between:1,100']);

    expect($schema->type)->toBe('number')
        ->and($schema->minimum)->toBe(1)
        ->and($schema->maximum)->toBe(100);
});

it('applies between constraint for arrays', function () {
    $schema = $this->mapper->mapRules(['array', 'between:2,5']);

    expect($schema->type)->toBe('array')
        ->and($schema->minItems)->toBe(2)
        ->and($schema->maxItems)->toBe(5);
});

it('applies in rule as enum values', function () {
    $schema = $this->mapper->mapRules(['string', 'in:active,inactive,pending']);

    expect($schema->type)->toBe('string')
        ->and($schema->enum)->toBe(['active', 'inactive', 'pending']);
});

it('handles pipe-delimited string rules', function () {
    $schema = $this->mapper->mapRules('string|min:3|max:255');

    expect($schema->type)->toBe('string')
        ->and($schema->minLength)->toBe(3)
        ->and($schema->maxLength)->toBe(255);
});

it('marks nullable schemas', function () {
    $schema = $this->mapper->mapRules(['nullable', 'string']);

    expect($schema->type)->toBe('string')
        ->and($schema->nullable)->toBeTrue();
});

it('maps boolean rule', function () {
    $schema = $this->mapper->mapRules(['boolean']);

    expect($schema->type)->toBe('boolean');
});

it('maps file rule to binary format', function () {
    $schema = $this->mapper->mapRules(['file']);

    expect($schema->type)->toBe('string')
        ->and($schema->format)->toBe('binary');
});

it('maps date rule to date format', function () {
    $schema = $this->mapper->mapRules(['date']);

    expect($schema->type)->toBe('string')
        ->and($schema->format)->toBe('date');
});
