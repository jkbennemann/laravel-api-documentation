<?php

declare(strict_types=1);

return [
    'open_api_version' => '3.0.2',
    'version' => '1.0.0',
    'title' => \Illuminate\Support\Str::title(config('app.name', 'Service')) . ' API Documentation',
    'include_vendor_routes' => false,
    'excluded_routes' => [
        //add the routes you want to exclude from the documentation
        //use the route name or the route uri.
        //you can use the wildcard * to exclude all routes that match the pattern.
        //you can use ! to exclude all routes except the ones that match the pattern.
        //for example: 'api/*' will exclude all routes that start with 'api/'
        //for example: '!api/*' will exclude all routes except the ones that start with 'api/'
    ],
    'excluded_methods' => [
        'HEAD',
        'OPTIONS',
        //'GET',
        //'POST',
        //'PUT',
        //'PATCH',
        //'DELETE',
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
    ]
];
