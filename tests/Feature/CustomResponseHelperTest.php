<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class CustomResponseHelperTest extends TestCase
{
    private ResponseAnalyzer $responseAnalyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseAnalyzer = app(ResponseAnalyzer::class);
    }

    public function test_detects_return_no_content_helper()
    {
        $methodBody = 'return $this->returnNoContent();';
        $reflection = new \ReflectionClass($this);

        $result = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeCustomResponseHelpers',
            [$methodBody, $reflection]
        );

        $this->assertNotEmpty($result);
        $this->assertEquals('object', $result['type']);
        $this->assertEquals(204, $result['status_code']);
        $this->assertEquals('custom_helper_no_content', $result['detection_method']);
        $this->assertStringContainsString('No content', $result['description']);
    }

    public function test_detects_return_accepted_helper()
    {
        $methodBody = 'return $this->returnAccepted();';
        $reflection = new \ReflectionClass($this);

        $result = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeCustomResponseHelpers',
            [$methodBody, $reflection]
        );

        $this->assertNotEmpty($result);
        $this->assertEquals('object', $result['type']);
        $this->assertEquals(202, $result['status_code']);
        $this->assertEquals('custom_helper_accepted', $result['detection_method']);
        $this->assertStringContainsString('accepted for processing', $result['description']);
    }

    public function test_detects_send_proxied_request()
    {
        $methodBody = 'return $this->sendProxiedRequest($request);';
        $reflection = new \ReflectionClass($this);

        $result = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeCustomResponseHelpers',
            [$methodBody, $reflection]
        );

        $this->assertNotEmpty($result);
        $this->assertEquals('object', $result['type']);
        $this->assertTrue($result['is_proxy']);
        $this->assertEquals('custom_send_proxied', $result['detection_method']);
        $this->assertArrayHasKey('data', $result['properties']);
    }

    public function test_detects_json_response_with_null()
    {
        $methodBody = 'return new JsonResponse(null, 204);';
        $reflection = new \ReflectionClass($this);

        $result = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeCustomResponseHelpers',
            [$methodBody, $reflection]
        );

        $this->assertNotEmpty($result);
        $this->assertEquals('object', $result['type']);
        $this->assertEquals(204, $result['status_code']);
        $this->assertEquals('json_response_empty', $result['detection_method']);
        $this->assertEquals([], $result['properties']);
    }

    public function test_detects_json_response_with_array()
    {
        $methodBody = 'return new JsonResponse([$data], 200);';
        $reflection = new \ReflectionClass($this);

        $result = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeCustomResponseHelpers',
            [$methodBody, $reflection]
        );

        $this->assertNotEmpty($result);
        $this->assertEquals('array', $result['type']);
        $this->assertEquals(200, $result['status_code']);
        $this->assertEquals('json_response_array', $result['detection_method']);
        $this->assertArrayHasKey('items', $result);
    }

    public function test_detects_custom_response_success_method()
    {
        $methodBody = 'return response()->success($data);';
        $reflection = new \ReflectionClass($this);

        $result = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeCustomResponseHelpers',
            [$methodBody, $reflection]
        );

        $this->assertNotEmpty($result);
        $this->assertEquals('object', $result['type']);
        $this->assertEquals('custom_response_success', $result['detection_method']);
        $this->assertArrayHasKey('success', $result['properties']);
        $this->assertArrayHasKey('message', $result['properties']);
    }

    public function test_no_detection_for_standard_patterns()
    {
        $methodBody = 'return response()->json($data);';
        $reflection = new \ReflectionClass($this);

        $result = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeCustomResponseHelpers',
            [$methodBody, $reflection]
        );

        $this->assertNull($result);
    }

    public function test_detects_send_dto_response()
    {
        $methodBody = 'return $this->sendDtoResponse($request);';
        $reflection = new \ReflectionClass($this);

        $result = $this->invokePrivateMethod(
            $this->responseAnalyzer,
            'analyzeCustomResponseHelpers',
            [$methodBody, $reflection]
        );

        $this->assertNotEmpty($result);
        $this->assertEquals('object', $result['type']);
        $this->assertEquals('custom_send_dto', $result['detection_method']);
        $this->assertArrayHasKey('data', $result['properties']);
        $this->assertArrayHasKey('id', $result['properties']);
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
