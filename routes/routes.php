<?php

declare(strict_types=1);

use Illuminate\Routing\Route;

if (config('api-documentation.ui.swagger.enabled', true)) {
    Route::get(
        config('api-documentation.ui.swagger.route'),
        [
            'as' => 'api-documentation.swagger',
            'middleware' => config('api-documentation.ui.swagger.middleware', []),
            'uses' => 'Bennemann\LaravelApiDocumentation\Http\Controllers\SwaggerController@index',
        ]
    );
}

if (config('api-documentation.ui.redoc.enabled', true)) {
    Route::get(
        config('api-documentation.ui.redoc.route'),
        [
            'as' => 'api-documentation.redoc',
            'middleware' => config('api-documentation.ui.redoc.middleware', []),
            'uses' => 'Bennemann\LaravelApiDocumentation\Http\Controllers\RedocController@index',
        ]
    );
}
