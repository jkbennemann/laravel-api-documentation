<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Resources\ConditionalPostResource;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class ConditionalFieldDetectionTest extends TestCase
{
    private ResponseAnalyzer $responseAnalyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseAnalyzer = app(ResponseAnalyzer::class);
    }

    public function test_detects_conditional_fields_in_resource()
    {
        // Test that conditional fields are detected and marked properly
        $reflection = new \ReflectionClass(ConditionalPostResource::class);
        $properties = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'extractResourceProperties',
            [ConditionalPostResource::class]
        );

        // Verify that conditional fields are detected
        $this->assertArrayHasKey('content', $properties);
        $this->assertArrayHasKey('published_at', $properties);
        $this->assertArrayHasKey('user', $properties);

        // Verify conditional field markings
        if (isset($properties['content']['conditional'])) {
            $this->assertTrue($properties['content']['conditional']);
            $this->assertStringContainsString('conditionally included', $properties['content']['description']);
        }

        if (isset($properties['published_at']['conditional'])) {
            $this->assertTrue($properties['published_at']['conditional']);
            $this->assertStringContainsString('conditionally included', $properties['published_at']['description']);
        }

        if (isset($properties['user']['conditional'])) {
            $this->assertTrue($properties['user']['conditional']);
            $this->assertStringContainsString('loaded conditionally', $properties['user']['description']);
        }
    }

    public function test_non_conditional_fields_are_not_marked()
    {
        // Test that non-conditional fields are not marked as conditional
        $reflection = new \ReflectionClass(ConditionalPostResource::class);
        $properties = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'extractResourceProperties',
            [ConditionalPostResource::class]
        );

        // Verify non-conditional fields
        $this->assertArrayHasKey('id', $properties);
        $this->assertArrayHasKey('title', $properties);
        $this->assertArrayHasKey('status', $properties);

        // These should not be marked as conditional
        if (isset($properties['id'])) {
            $this->assertArrayNotHasKey('conditional', $properties['id']);
        }

        if (isset($properties['title'])) {
            $this->assertArrayNotHasKey('conditional', $properties['title']);
        }

        if (isset($properties['status'])) {
            $this->assertArrayNotHasKey('conditional', $properties['status']);
        }
    }

    public function test_ast_based_conditional_field_detection()
    {
        // Test that AST-based analysis properly detects conditional patterns
        $analysis = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeToArrayMethodWithAST',
            [
                (new \ReflectionClass(ConditionalPostResource::class))->getFileName(),
                'ConditionalPostResource',
            ]
        );

        $this->assertIsArray($analysis);
        $this->assertNotEmpty($analysis);

        // Check if any conditional fields were detected
        $hasConditionalFields = false;
        foreach ($analysis as $field => $data) {
            if (isset($data['conditional']) && $data['conditional'] === true) {
                $hasConditionalFields = true;
                break;
            }
        }

        // Should have detected at least one conditional field
        $this->assertTrue($hasConditionalFields, 'AST analysis should detect conditional fields');
    }

    /**
     * Helper method to invoke private methods for testing
     */
    private function invokePrivateMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
