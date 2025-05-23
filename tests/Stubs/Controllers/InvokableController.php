<?php

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Attributes\Summary;
use JkBennemann\LaravelApiDocumentation\Attributes\Description;
use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;

/**
 * Invokable controller for testing modern Laravel controller patterns
 */
#[Tag('Invokable')]
#[Summary('Get application status')]
#[Description('Returns the current application status and health information')]
class InvokableController
{
    /**
     * Handle the incoming request using __invoke method
     * 
     * @return JsonResponse
     */
    #[DataResponse(200, description: 'Application status retrieved successfully', resource: [
        'status' => 'string',
        'uptime' => 'integer',
        'version' => 'string'
    ])]
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'uptime' => time() - LARAVEL_START,
            'version' => app()->version(),
        ]);
    }
}
