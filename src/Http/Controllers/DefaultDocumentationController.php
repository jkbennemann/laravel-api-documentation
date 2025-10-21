<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Http\Controllers;

use JkBennemann\LaravelApiDocumentation\Traits\FileVisibilityTrait;

class DefaultDocumentationController
{
    use FileVisibilityTrait;

    public function index()
    {
        $defaultUi = $this->getDefaultUiForCurrentDomain();

        // Route to the appropriate UI controller based on domain configuration
        switch ($defaultUi) {
            case 'redoc':
                return app(RedocController::class)->index();
            case 'scalar':
                return app(ScalarController::class)->index();
            case 'swagger':
            default:
                return app(SwaggerController::class)->index();
        }
    }
}
