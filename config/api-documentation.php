<?php

declare(strict_types=1);

return [
    'open_api_version' => '3.0.2',
    'version' => '1.0.0',
    'title' => \Illuminate\Support\Str::title(config('app.name', 'Service')) . ' API Documentation',
    'include_vendor_routes' => false,
    'excluded_routes' => [
        'documentation',
        'documentation/swagger',
        'documentation/redoc',
        '!api/v1/organizations/*/billing-profiles',
//        'api/v1/users/*/roles',
//        'api/v1/users/update*',
    ],
    'excluded_methods' => [
        'HEAD',
        'GET',
        'POST',
//        'PUT',
        'PATCH',
        'DELETE',
    ],
    'servers' => [
        [
            'url' => 'https://user-service.raidboxes.io',
            'description' => 'Production',
        ],
        [
            'url' => 'https://staging-user-service.raidboxes.io',
            'description' => 'Staging',
        ],
    ],
    'ui' => [
        'default' => 'swagger',
        'swagger' => [
            'enabled' => true,
            'route' => '/documentation/swagger',
            'version' => '5.17.14',
            'middleware' => [
                'web',
            ],
        ],
        'redoc' => [
            'enabled' => true,
            'route' => '/documentation/redoc',
            'version' => '2.2.0',
            'middleware' => [
                'web',
            ],
        ],
    ]
];
