<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers;

use Illuminate\Http\Request;

class EnhancedQueryParamController
{
    /**
     * Test method with different query parameter annotation formats
     *
     * @queryParam page int Page number for pagination. Example: 1
     * @queryParam per_page int Items per page. Example: 10
     * @queryParam {string} search Search term to filter results
     * @queryParam status Filter by status (optional)
     * @queryParam sort_by string|array Sort field or array of sort fields
     * @queryParam createdAt Date filter for creation date. Example: 2023-01-01
     * @queryParam userEmail User email address to filter by. Example: user@example.com
     *
     * @return array
     */
    public function index(Request $request)
    {
        return [
            'success' => true,
            'data' => [],
        ];
    }

    /**
     * Method with type inferred format parameters
     *
     * @queryParam userId int64 User ID for filtering
     * @queryParam apiUrl URL to the external API
     * @queryParam created_date Creation date filter
     * @queryParam password User password
     *
     * @return array
     */
    public function formatDetection(Request $request)
    {
        return [
            'success' => true,
            'data' => [],
        ];
    }
}
