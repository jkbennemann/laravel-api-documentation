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
        'storage' => [
            'disk' => 'public',
            'filename' => 'api-documentation.json',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Features Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the package analyzes and generates documentation.
    | Smart features include automatic request/response analysis and more.
    |
    */
    'smart_features' => true,

    /*
    |--------------------------------------------------------------------------
    | Smart Response Generation Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the package analyzes and generates response documentation.
    | The priority order is:
    | 1. Parameter attributes on class
    | 2. Parameter attributes on toArray method
    | 3. PHPDoc properties
    | 4. Automatic toArray analysis
    |
    */
    'smart_responses' => [
        // Enable or disable smart response generation
        'enabled' => true,

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

        // Default types for common Laravel methods
        'method_types' => [
            'toDateString' => ['type' => 'string', 'format' => 'date'],
            'toDateTimeString' => ['type' => 'string', 'format' => 'date-time'],
            'format' => ['type' => 'string', 'format' => 'date-time'],
        ],

        // Configure pagination response structure
        'pagination' => [
            'enabled' => true,
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
    | Configure how the package analyzes and generates request documentation.
    | The priority order is:
    | 1. Parameter attributes on request class
    | 2. Validation rules analysis
    |
    */
    'smart_requests' => [
        // Enable or disable smart request analysis
        'enabled' => true,

        // Map Laravel validation rules to OpenAPI types
        'rule_types' => [
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'numeric' => ['type' => 'number'],
            'array' => ['type' => 'array'],
            'object' => ['type' => 'object'],
            'file' => ['type' => 'string', 'format' => 'binary'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'date_format' => ['type' => 'string', 'format' => 'date-time'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            'ip' => ['type' => 'string', 'format' => 'ipv4'],
            'ipv4' => ['type' => 'string', 'format' => 'ipv4'],
            'ipv6' => ['type' => 'string', 'format' => 'ipv6'],
            'json' => ['type' => 'string', 'format' => 'json'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
        ],
    ],
];
