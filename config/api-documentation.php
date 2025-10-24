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
    /*
    |--------------------------------------------------------------------------
    | Domain Configuration
    |--------------------------------------------------------------------------
    |
    | Configure multiple documentation files for different domains/audiences.
    | Each domain can have its own:
    |   - Title, description, terms of service, and contact information
    |   - Default UI viewer (swagger, redoc, or scalar)
    |   - Server URLs (with production/development separation)
    |   - Alternative UI viewer links in the description
    |
    | Domain Routing:
    |   The system automatically detects which domain is being accessed based on
    |   the request host and serves the appropriate documentation file.
    |
    | Example Use Cases:
    |   - Public API (api.example.com) → Modern Scalar UI, customer-friendly
    |   - Internal API (gateway.example.com) → Swagger UI, developer-focused
    |
    */
    'domains' => [
        'default' => [
            'title' => Str::title(env('APP_NAME', 'Service')).' API Documentation',
            'description' => null, // Optional: Add a description/summary for the API (supports HTML)
            'termsOfService' => null, // Optional: URL to terms of service
            'contact' => null, // Optional: ['name' => 'Support', 'email' => 'support@example.com', 'url' => 'https://example.com/support']
            'default_ui' => null, // Optional: Default UI viewer for this domain ('swagger', 'redoc', or 'scalar'). Overrides global default.
            'append_alternative_uis' => false, // Optional: Append links to alternative UI viewers in the description
            'main' => env('APP_URL', 'http://localhost'),
            'servers' => [
                [
                    'url' => env('APP_URL', 'http://localhost'),
                    'description' => env('APP_NAME', 'Service'),
                ],
            ],
        ],

        // Example: Add additional domains for different API documentation files
        // 'public-api' => [
        //     'title' => 'Public API',
        //     'description' => 'Public-facing API for customers. Access resources through a RESTful interface.',
        //     'termsOfService' => 'https://example.com/terms',
        //     'contact' => [
        //         'name' => 'API Support',
        //         'email' => 'api-support@example.com',
        //         'url' => 'https://example.com/support',
        //     ],
        //     'default_ui' => 'scalar', // Use Scalar UI as default for this domain
        //     'append_alternative_uis' => true, // Show links to other UI viewers
        //     'main' => env('PUBLIC_API_URL', 'http://api.test'),
        //     'servers' => [
        //         // Production server (always included)
        //         [
        //             'url' => 'https://api.example.com',
        //             'description' => 'Production',
        //         ],
        //         // Development server (only when using --dev flag)
        //         [
        //             'url' => env('PUBLIC_API_URL', 'http://api.test'),
        //             'description' => 'Local Development',
        //             'development' => true, // Marker for development-only server
        //         ],
        //     ],
        // ],
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

    // Error response enhancement configuration
    'error_responses' => [
        // Enable/disable enhanced error response generation
        'enabled' => true,

        // Default error message settings
        'defaults' => [
            // Default application name used in i18n keys
            'app_name' => env('APP_NAME', 'app'),

            // Default path generation pattern
            'path_pattern' => '/api/v1/{controller}',

            // Request ID generation pattern (use {random} for random characters)
            'request_id_pattern' => 'req_{random}',

            // Default error messages for status codes
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

        // Custom domain-specific error message templates
        'domains' => [
            // You can override or add new domain templates
            // Example:
            // 'custom_domain' => [
            //     'custom_context' => [
            //         '422' => [
            //             'field_name' => 'Custom error message for this field',
            //         ],
            //         '401' => 'Custom unauthorized message for this domain',
            //     ],
            // ],
        ],

        // Custom validation rule message overrides
        'validation_messages' => [
            // Override default validation messages
            // Example:
            // 'required' => 'The :attribute field is mandatory.',
            // 'email' => 'Please provide a valid :attribute.',
        ],

        // Custom validation message templates from files
        'template_files' => [
            // Load validation message templates from files
            // 'validation_rules' => resource_path('api-docs/validation-messages.php'),
            // 'domain_templates' => resource_path('api-docs/domain-templates.php'),
            // 'field_labels' => resource_path('api-docs/field-labels.php'),
        ],

        // Custom field label mappings
        'field_labels' => [
            // Override default field labels
            // Example:
            // 'user_id' => 'user identifier',
            // 'subscription_id' => 'subscription identifier',
        ],

        // Domain detection configuration
        'domain_detection' => [
            // Custom patterns for domain detection based on controller names
            'patterns' => [
                // Pattern => [domain, context]
                // Example:
                // '*Auth*' => ['authentication', 'default'],
                // '*Payment*' => ['billing', 'payment'],
            ],
        ],

        // Error response schema customization
        'schema' => [
            // Include additional fields in error response schema
            'additional_fields' => [
                // Example:
                // 'correlation_id' => ['type' => 'string', 'description' => 'Request correlation identifier'],
            ],

            // Customize validation error details structure
            'validation_details' => [
                'enabled' => true,
                'field_name' => 'details', // Field name for validation errors
                'include_rules' => true,    // Include validation rule information
            ],
        ],

        // Custom error example generation
        'examples' => [
            // Enable example generation for error responses
            'enabled' => true,

            // Include validation details in examples
            'include_validation_details' => true,

            // Use realistic timestamps (false = use placeholder)
            'realistic_timestamps' => true,

            // Use realistic request IDs (false = use placeholder)
            'realistic_request_ids' => true,
        ],

        // Localization configuration for error messages
        'localization' => [
            // Default locale for error message generation
            'default_locale' => env('APP_LOCALE', 'en'),

            // Available locales for error message generation
            'available_locales' => ['en', 'es', 'fr', 'de', 'pt', 'ja', 'zh'],

            // Enable Laravel translation integration
            'use_laravel_translations' => true,

            // Locale-specific validation message overrides
            'validation_messages' => [
                // Example for Spanish
                // 'es' => [
                //     'required' => 'El campo :attribute es obligatorio.',
                //     'email' => 'El :attribute debe ser una dirección de correo válida.',
                // ],
                // Example for French
                // 'fr' => [
                //     'required' => 'Le champ :attribute est requis.',
                //     'email' => 'Le :attribute doit être une adresse e-mail valide.',
                // ],
            ],

            // Locale-specific field label overrides
            'field_labels' => [
                // Example for Spanish
                // 'es' => [
                //     'email' => 'correo electrónico',
                //     'password' => 'contraseña',
                // ],
                // Example for French
                // 'fr' => [
                //     'email' => 'adresse e-mail',
                //     'password' => 'mot de passe',
                // ],
            ],

            // Locale-specific domain templates
            'domains' => [
                // Example for Spanish
                // 'es' => [
                //     'authentication' => [
                //         'login' => [
                //             '401' => 'Credenciales inválidas proporcionadas.',
                //             '422' => [
                //                 'email' => 'Por favor proporcione una dirección de correo válida.',
                //                 'password' => 'La contraseña es requerida.',
                //             ],
                //         ],
                //     ],
                // ],
            ],
        ],
    ],

    'app' => [
        'port' => env('APP_PORT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Response Capture Configuration
    |--------------------------------------------------------------------------
    |
    | Capture actual API responses during testing for 95%+ accurate documentation.
    | IMPORTANT: Never enable capture in production!
    |
    */
    'capture' => [
        // Only enable in local/testing environments
        'enabled' => env('DOC_CAPTURE_MODE', false),

        // Where to store captured responses (commit to version control)
        'storage_path' => base_path('.schemas/responses'),

        // What to capture
        'capture' => [
            'requests' => true,   // Capture request bodies and query parameters
            'responses' => true,  // Capture response bodies
            'headers' => true,    // Capture request/response headers
            'examples' => true,   // Generate examples from captured data
        ],

        // Sanitization rules for sensitive data
        'sanitize' => [
            'enabled' => true,
            'sensitive_keys' => [
                'password',
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
            ],
            'redacted_value' => '***REDACTED***',
        ],

        // Capture rules
        'rules' => [
            // Include error responses in capture
            'include_errors' => true,

            // Maximum response size to capture (bytes)
            'max_size' => 1024 * 100, // 100KB

            // Routes to exclude from capture
            'exclude_routes' => [
                'telescope/*',
                'horizon/*',
                '_debugbar/*',
                'sanctum/*',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation Generation Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how documentation is generated from static analysis + captured data
    |
    | Development Servers:
    |   Use the --dev flag to include development servers in generated documentation:
    |   php artisan documentation:generate --dev
    |
    |   Without the flag, only production servers are included.
    |   Servers with 'development' => true are filtered out by default.
    |
    */
    'generation' => [
        // Use captured response data when available
        'use_captured' => true,

        // Priority: 'captured_priority' (recommended) or 'static_priority'
        'merge_strategy' => 'captured_priority',

        // Fallback to static analysis when no captured data
        'fallback_to_static' => true,

        // Warnings
        'warn_missing_captures' => false,
        'warn_low_accuracy' => true,
    ],
];
