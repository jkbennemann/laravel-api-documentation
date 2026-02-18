<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'open_api_version' => '3.1.0',
    'version' => '1.0.0',
    'title' => Str::title(env('APP_NAME', 'Service')).' API Documentation',
    'include_vendor_routes' => false,
    'include_closure_routes' => false,
    'auto_detect_api_routes' => true,
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
            'proxy_url' => 'https://proxy.scalar.com',
            'middleware' => [
                'web',
            ],
        ],
        'storage' => [
            'disk' => 'public',
            'filename' => 'api-documentation.json',
            'files' => [
                'default' => [
                    'name' => 'Default doc',
                    'filename' => 'api-documentation.json',
                    'process' => true,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain Configuration
    |--------------------------------------------------------------------------
    */
    'domains' => [
        'default' => [
            'title' => Str::title(env('APP_NAME', 'Service')).' API Documentation',
            'description' => null,
            'terms_of_service' => null,
            'contact' => null,
            'default_ui' => null,
            'append_alternative_uis' => false,
            'main' => env('APP_URL', 'http://localhost'),
            'servers' => [
                [
                    'url' => env('APP_URL', 'http://localhost'),
                    'description' => env('APP_NAME', 'Service'),
                ],
            ],
            'tags' => [],
            'tag_groups' => [],
            'tag_groups_include_ungrouped' => true,
            'trait_tags' => [],
            'external_docs' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Code Sample Generation
    |--------------------------------------------------------------------------
    |
    | Generate multi-language code samples (x-codeSamples) on every operation.
    | Rendered natively by ReDoc and Scalar. Languages: bash (cURL), javascript
    | (fetch), php (Guzzle), python (requests).
    |
    */
    'code_samples' => [
        'enabled' => false,
        'languages' => ['bash', 'javascript', 'php', 'python'],
        'base_url' => null, // null uses '{baseUrl}' placeholder
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Scheme Configuration
    |--------------------------------------------------------------------------
    |
    | Configure API key authentication detection. When enabled, routes using
    | any of the listed middleware names will get an apiKey security scheme
    | in the OpenAPI spec.
    |
    */
    'security' => [
        'api_key' => [
            'enabled' => false,
            'header' => 'X-API-KEY',
            'scheme_name' => 'apiKeyAuth',
            'description' => 'API key passed via request header',
            'middleware' => ['auth.apikey', 'apikey', 'auth.api-key', 'auth.api_key', 'api-key', 'api_key'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tag Descriptions
    |--------------------------------------------------------------------------
    |
    | Add descriptions to OpenAPI tags. Descriptions support Markdown and
    | appear in ReDoc/Scalar as introductory content for tag sections.
    | These override any descriptions set via #[Tag] attributes.
    |
    */
    'tags' => [
        // 'Users' => 'Operations for managing user accounts.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tag Groups (x-tagGroups)
    |--------------------------------------------------------------------------
    |
    | Group tags into sections for ReDoc/Scalar navigation sidebar.
    | Each entry needs a 'name' and a 'tags' array.
    |
    */
    'tag_groups' => [
        // ['name' => 'User Management', 'tags' => ['Users', 'Roles']],
    ],

    // When true, tags not assigned to any group are collected into an "Other" group.
    // When false, ungrouped tags are omitted from the sidebar (default ReDoc/Scalar behavior).
    'tag_groups_include_ungrouped' => true,

    /*
    |--------------------------------------------------------------------------
    | Trait Tags (x-traitTag)
    |--------------------------------------------------------------------------
    |
    | Documentation-only tags that appear in the sidebar but are not
    | associated with any operations. Useful for guides and introductions.
    |
    */
    'trait_tags' => [
        // ['name' => 'Getting Started', 'description' => '# Welcome\nIntroductory content here.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | External Documentation
    |--------------------------------------------------------------------------
    |
    | Spec-level externalDocs link shown in documentation viewers.
    |
    */
    'external_docs' => null,
    // 'external_docs' => ['url' => 'https://docs.example.com', 'description' => 'Full documentation'],

    /*
    |--------------------------------------------------------------------------
    | Plugin Configuration
    |--------------------------------------------------------------------------
    |
    | Register additional plugins or disable auto-discovered ones.
    |
    */
    'plugins' => [
        // Additional plugin classes to register
        // Example: \App\Docs\CustomPlugin::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Configuration
    |--------------------------------------------------------------------------
    */
    'analysis' => [
        // Priority: 'static_first' (attributes/analysis override runtime) or 'captured_first' (runtime overrides)
        'priority' => 'static_first',

        // Cache TTL for AST parsing results (seconds, 0 = disabled)
        'cache_ttl' => 3600,

        // Cache directory for AST analysis
        'cache_path' => null, // defaults to storage_path('framework/cache/api-docs')
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Response Generation Configuration
    |--------------------------------------------------------------------------
    */
    'smart_responses' => [
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Request Analysis Configuration
    |--------------------------------------------------------------------------
    */
    'smart_requests' => [
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

    /*
    |--------------------------------------------------------------------------
    | Error Response Configuration
    |--------------------------------------------------------------------------
    */
    'error_responses' => [
        'enabled' => true,
        'defaults' => [
            'status_messages' => [
                '400' => 'The request could not be processed due to invalid syntax.',
                '401' => 'Authentication credentials are required.',
                '403' => 'You do not have permission to access this resource.',
                '404' => 'The requested resource was not found.',
                '422' => 'The request contains invalid data.',
                '429' => 'Too many requests. Please try again later.',
                '500' => 'An internal server error occurred. Please try again later.',
                '503' => 'The service is temporarily unavailable. Please try again later.',
            ],
        ],
        'domains' => [],
        'domain_detection' => [
            'patterns' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Response Capture Configuration
    |--------------------------------------------------------------------------
    */
    'capture' => [
        'enabled' => env('DOC_CAPTURE_MODE', false),
        'storage_path' => base_path('.schemas/responses'),
        'capture' => [
            'requests' => true,
            'responses' => true,
            'headers' => true,
            'examples' => true,
        ],
        'sanitize' => [
            'enabled' => true,
            'sensitive_keys' => [
                // Authentication
                'password',
                'passwd',
                'token',
                'secret',
                'api_key',
                'apiKey',
                'access_token',
                'refresh_token',
                'private_key',
                'authorization',
                'x-api-key',
                'bearer',
                'jwt',
                'session',
                'csrf',
                'xsrf',
                'oauth',
                'credential',
                'signature',
                // Financial
                'credit_card',
                'card_number',
                'cvv',
                'cvc',
                'pin',
                // Personal
                'ssn',
                'social_security',
                'tax_id',
            ],
            'redacted_value' => '***REDACTED***',
        ],
        'rules' => [
            'max_size' => 1024 * 100,
            'exclude_routes' => [
                'telescope/*',
                'horizon/*',
                '_debugbar/*',
                'sanctum/*',
            ],
        ],
    ],
];
