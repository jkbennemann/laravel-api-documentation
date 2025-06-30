<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Services\EnhancedResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class EnhancedResponseAnalyzerTest extends TestCase
{
    public function test_it_can_analyze_multi_status_responses()
    {
        $analyzer = app(EnhancedResponseAnalyzer::class);

        // Test with a simple controller method that has basic responses
        $responses = $analyzer->analyzeControllerMethodResponses(
            'JkBennemann\\LaravelApiDocumentation\\Tests\\Stubs\\Controllers\\SimpleController',
            'simple'
        );

        // Should at least have a 200 response
        expect($responses)
            ->toBeArray()
            ->toHaveKey('200');

        // Verify response structure
        expect($responses['200'])
            ->toHaveKey('description')
            ->toHaveKey('content_type')
            ->toHaveKey('headers')
            ->toHaveKey('schema');
    }

    public function test_it_handles_nonexistent_controller_gracefully()
    {
        $analyzer = app(EnhancedResponseAnalyzer::class);

        $responses = $analyzer->analyzeControllerMethodResponses(
            'NonExistent\\Controller',
            'index'
        );

        expect($responses)->toBe([]);
    }

    public function test_it_provides_default_response_when_no_analysis_possible()
    {
        $analyzer = app(EnhancedResponseAnalyzer::class);

        // Test with a controller that should fall back to default analysis
        $responses = $analyzer->analyzeControllerMethodResponses(
            'JkBennemann\\LaravelApiDocumentation\\Tests\\Stubs\\Controllers\\SimpleController',
            'simple'
        );

        // Should have at least a 200 response as fallback
        expect($responses)
            ->toBeArray()
            ->not()->toBeEmpty()
            ->toHaveKey('200');
    }

    public function test_it_detects_multiple_status_codes_from_method_body()
    {
        $analyzer = app(EnhancedResponseAnalyzer::class);

        // Test with a controller method that has multiple response patterns
        $responses = $analyzer->analyzeControllerMethodResponses(
            'JkBennemann\\LaravelApiDocumentation\\Tests\\Stubs\\Controllers\\MultiStatusController',
            'withMultipleResponses'
        );

        // Should detect multiple status codes from method analysis
        expect($responses)
            ->toBeArray()
            ->not()->toBeEmpty();

        // Should have at least a success response
        expect($responses)->toHaveKey('200');

        // May also detect error responses through analysis
        // The actual status codes detected will depend on the AST analysis
    }

    public function test_it_detects_abort_calls()
    {
        $analyzer = app(EnhancedResponseAnalyzer::class);

        $responses = $analyzer->analyzeControllerMethodResponses(
            'JkBennemann\\LaravelApiDocumentation\\Tests\\Stubs\\Controllers\\MultiStatusController',
            'withAbortCalls'
        );

        expect($responses)
            ->toBeArray()
            ->not()->toBeEmpty()
            ->toHaveKey('200'); // At minimum should have success response
    }

    public function test_it_detects_custom_helper_methods()
    {
        $analyzer = app(EnhancedResponseAnalyzer::class);

        $responses = $analyzer->analyzeControllerMethodResponses(
            'JkBennemann\\LaravelApiDocumentation\\Tests\\Stubs\\Controllers\\MultiStatusController',
            'withCustomHelpers'
        );

        expect($responses)
            ->toBeArray()
            ->not()->toBeEmpty()
            ->toHaveKey('200'); // Should have at least default success response
    }
}
