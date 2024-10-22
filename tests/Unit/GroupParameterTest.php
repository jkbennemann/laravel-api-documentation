<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Services\RuleParser;

it('can process single rules into a tree structure', function () {
    $rules = [
        'parameter_1' => 'required|string',
        'parameter_2' => 'email',
    ];

    expect(RuleParser::parse($rules))->toBe(expectedSingleResult());
});

it('can process single rules from an array into a tree structure', function () {
    $rules = [
        'parameter_1' => [
            'required',
            'string',
        ],
        'parameter_2' => [
            'email',
        ],
    ];

    expect(RuleParser::parse($rules))->toBe(expectedSingleResult());
});

it('can process single rules from an from combined rules into a tree structure', function () {
    $rules = [
        'parameter_1' => 'required|string',
        'parameter_2' => [
            'email',
        ],
    ];

    expect(RuleParser::parse($rules))->toBe(expectedSingleResult());
});

it('can process nested rules into a tree structure', function () {
    $rules = [
        'base' => 'required|array',
        'base.parameter_1' => 'required|string',
        'base.parameter_2' => 'email',
    ];

    expect(RuleParser::parse($rules))->toBe(expectedNestedResult());
});

function expectedNestedResult(): array
{
    return [
        'base' => [
            'name' => 'base',
            'description' => null,
            'type' => 'array',
            'format' => null,
            'required' => true,
            'deprecated' => false,
            'parameters' => [
                'parameter_1' => [
                    'name' => 'parameter_1',
                    'description' => null,
                    'type' => 'string',
                    'format' => null,
                    'required' => true,
                    'deprecated' => false,
                    'parameters' => [],
                ],
                'parameter_2' => [
                    'name' => 'parameter_2',
                    'description' => null,
                    'type' => 'string',
                    'format' => 'email',
                    'required' => false,
                    'deprecated' => false,
                    'parameters' => [],
                ],
            ],
        ],
    ];
}

function expectedSingleResult(): array
{
    return [
        'parameter_1' => [
            'name' => 'parameter_1',
            'description' => null,
            'type' => 'string',
            'format' => null,
            'required' => true,
            'deprecated' => false,
            'parameters' => [],
        ],
        'parameter_2' => [
            'name' => 'parameter_2',
            'description' => null,
            'type' => 'string',
            'format' => 'email',
            'required' => false,
            'deprecated' => false,
            'parameters' => [],
        ],
    ];
}
