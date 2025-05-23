<?php

use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DtoController;

it('processes return types without throwing union type errors', function () {
    $routeComposition = app(RouteComposition::class);
    
    // Test that RouteComposition can handle PHP 8.0+ reflection without errors
    // This test validates the fix for ReflectionUnionType::getName() call
    
    // Use reflection to check if the processReturnType method can handle various types
    $processMethod = new ReflectionMethod($routeComposition, 'processReturnType');
    $processMethod->setAccessible(true);
    
    // Test with an existing method from DtoController
    $method = new ReflectionMethod(DtoController::class, 'nestedSimple');
    
    // This should not throw any errors - the fix ensures union types are handled
    $result = $processMethod->invoke($routeComposition, $method, DtoController::class, 'nestedSimple');
    
    expect($result)->toBeArray();
});

it('validates union type fix is working', function () {
    // This is a regression test for the union type bug
    // If this test passes, it means the fix in RouteComposition::processReturnType works
    
    $routeComposition = app(RouteComposition::class);
    
    // Create a simple mock method that would have union return type
    $mockClass = new class {
        public function testMethod(): array|object {
            return [];
        }
    };
    
    $method = new ReflectionMethod($mockClass, 'testMethod');
    $returnType = $method->getReturnType();
    
    // Validate that this is indeed a union type
    expect($returnType)->toBeInstanceOf(ReflectionUnionType::class);
    
    // Test that our fix handles this correctly
    $processMethod = new ReflectionMethod($routeComposition, 'processReturnType');
    $processMethod->setAccessible(true);
    
    // This should not throw "Call to undefined method ReflectionUnionType::getName()"
    $result = $processMethod->invoke($routeComposition, $method, get_class($mockClass), 'testMethod');
    
    expect($result)->toBeArray();
});

it('confirms union type processing extracts types correctly', function () {
    // Create a test class with union return type
    $mockClass = new class {
        public function unionMethod(): \Illuminate\Http\JsonResponse|\Illuminate\Http\Response {
            return response()->json([]);
        }
    };
    
    $method = new ReflectionMethod($mockClass, 'unionMethod');
    $returnType = $method->getReturnType();
    
    // Confirm this is a union type and has multiple types
    expect($returnType)->toBeInstanceOf(ReflectionUnionType::class);
    expect($returnType->getTypes())->toHaveCount(2);
    
    // Test that our RouteComposition handles this without errors
    $routeComposition = app(RouteComposition::class);
    $processMethod = new ReflectionMethod($routeComposition, 'processReturnType');
    $processMethod->setAccessible(true);
    
    // The fix should handle union types gracefully
    $result = $processMethod->invoke($routeComposition, $method, get_class($mockClass), 'unionMethod');
    
    expect($result)->toBeArray()
        ->and($result)->toHaveKey(200)
        ->and($result[200])->toHaveKey('type')
        ->and($result[200]['type'])->toBe('object');
});
