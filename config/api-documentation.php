<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'open_api_version' => '3.0.2',
    'version' => '1.0.0',
    'title' => Str::title(env('APP_NAME', 'Service')).' API Documentation',
    'include_vendor_routes' => false,
    'excluded_routes' => [
        // add the routes you want to exclude from the documentation
        // use the route name or the route uri.
        // you can use the wildcard * to exclude all routes that match the pattern.
        // you can use ! to exclude all routes except the ones that match the pattern.
        // for example: 'api/*' will exclude all routes that start with 'api/'
        // for example: '!api/*' will exclude all routes except the ones that start with 'api/'
    ],
    'excluded_methods' => [
        'HEAD',
        'OPTIONS',
        // 'GET',
        // 'POST',
        // 'PUT',
        // 'PATCH',
        // 'DELETE',
    ],
    'servers' => [
        [
            'url' => env('APP_URL', 'http://localhost'),
            'description' => env('APP_NAME', 'Service'),
        ],
    ],
    'ui' => [
        'default' => 'swagger',
        'swagger' => [
            'enabled' => false,
            'route' => '/documentation/swagger',
            'version' => '5.17.14',
            'middleware' => [
                'web',
            ],
        ],
        'redoc' => [
            'enabled' => false,
            'route' => '/documentation/redoc',
            'version' => '2.2.0',
            'middleware' => [
                'web',
            ],
        ],
        'scalar' => [
            'enabled' => false,
            'route' => '/documentation/scalar',
            'version' => '2.2.0',
            'middleware' => [
                'web',
            ],
        ],
        'storage' => [
            'disk' => 'public',
            'filename' => 'api-documentation.json',
            'default_file' => 'default',
            'files' => [
                'default' => [
                    'name' => 'Default doc',
                    'filename' => 'api-documentation.json',
                    'process' => true,
                ],
            ],
        ],
    ],
    'domains' => [
        'default' => [
            'title' => Str::title(env('APP_NAME', 'Service')).' API Documentation',
            'main' => env('APP_URL', 'http://localhost'),
            'servers' => [
                [
                    'url' => env('APP_URL', 'http://localhost'),
                    'description' => env('APP_NAME', 'Service'),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Response Generation Configuration
    |--------------------------------------------------------------------------
    |
    | Smart features are always enabled for 100% accurate documentation.
    | The priority order is:
    | 1. Parameter attributes on class
    | 2. Parameter attributes on toArray method
    | 3. PHPDoc properties
    | 4. Automatic toArray analysis
    |
    */
    'smart_responses' => [

        // Configure type mapping for relationship methods
        'relationship_types' => [
            'hasOne' => ['type' => 'object'],
            'belongsTo' => ['type' => 'object'],
            'morphOne' => ['type' => 'object'],
            'hasMany' => ['type' => 'array', 'items' => ['type' => 'object']],
            'belongsToMany' => ['type' => 'array', 'items' => ['type' => 'object']],
            'morphMany' => ['type' => 'array', 'items' => ['type' => 'object']],
            'morphToMany' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],

        // Enhanced method type detection for 100% accuracy
        'method_types' => [
            'toDateString' => ['type' => 'string', 'format' => 'date'],
            'toDateTimeString' => ['type' => 'string', 'format' => 'date-time'],
            'toIso8601String' => ['type' => 'string', 'format' => 'date-time'],
            'format' => ['type' => 'string', 'format' => 'date-time'],
            'toString' => ['type' => 'string'],
            'toArray' => ['type' => 'array'],
            'toJson' => ['type' => 'string', 'format' => 'json'],
            'value' => ['type' => 'string'],
        ],

        // Configure pagination response structure
        'pagination' => [
            'structure' => [
                'data' => true,
                'meta' => true,
                'links' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Request Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Smart request analysis is always enabled for accurate documentation.
    | The priority order is:
    | 1. Parameter attributes on request class
    | 2. Validation rules analysis
    |
    */
    'smart_requests' => [

        // Enhanced Laravel validation rule mapping for 100% accuracy
        'rule_types' => [
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'numeric' => ['type' => 'number'],
            'array' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            'file' => ['type' => 'string', 'format' => 'binary'],
            'image' => ['type' => 'string', 'format' => 'binary'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'date_format' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'ip' => ['type' => 'string', 'format' => 'ipv4'],
            'ipv4' => ['type' => 'string', 'format' => 'ipv4'],
            'ipv6' => ['type' => 'string', 'format' => 'ipv6'],
            'json' => ['type' => 'string', 'format' => 'json'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            'alpha' => ['type' => 'string', 'pattern' => '^[a-zA-Z]+$'],
            'alpha_num' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9]+$'],
            'alpha_dash' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]+$'],
            'regex' => ['type' => 'string'],
            'digits' => ['type' => 'string', 'pattern' => '^[0-9]+$'],
            'digits_between' => ['type' => 'string'],
        ],
    ],
    'app' => [
        'port' => env('APP_PORT'),
    ],
];
