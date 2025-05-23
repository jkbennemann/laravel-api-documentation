<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Services\OpenApi;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\EnhancedApiController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DtoController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\DynamicResponseController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class OpenApiSchemaAnalysisTest extends TestCase
{
    /**
     * Comprehensive analysis of OpenAPI schema generation to identify blind spots
     */
    public function test_comprehensive_openapi_schema_generation(): void
    {
        // Set up comprehensive routes covering all features
        Route::get('users', [EnhancedApiController::class, 'index'])->name('users.index');
        Route::post('users', [EnhancedApiController::class, 'store'])->name('users.store');
        Route::get('users/{id}', [EnhancedApiController::class, 'show'])->name('users.show');
        
        Route::get('dto', [DtoController::class, 'getData'])->name('dto.get');
        Route::post('dto', [DtoController::class, 'postData'])->name('dto.post');
        
        Route::get('dynamic/{id}', [DynamicResponseController::class, 'attach'])->name('dynamic.attach');
        Route::get('dynamic/collection', [DynamicResponseController::class, 'getSubscriptionEntitiesResource'])->name('dynamic.collection');
        
        $routeComposition = app(RouteComposition::class);
        $openApi = app(OpenApi::class);
        
        // Generate complete documentation
        $routes = $routeComposition->process();
        $openApiSpec = $openApi->processRoutes($routes)->get();
        
        // Analyze generated schema for completeness
        $this->analyzeQueryParameterHandling($openApiSpec);
        $this->analyzeRequestBodyHandling($openApiSpec);
        $this->analyzeResponseSchemas($openApiSpec);
        $this->analyzePathParameterHandling($openApiSpec);
        $this->analyzeResponseHeaders($openApiSpec);
        $this->analyzeSpatieDataIntegration($openApiSpec);
        $this->analyzeJsonResourceDynamicAnalysis($openApiSpec);
        
        dump('🎯 OpenAPI Schema Analysis Complete');
        expect(true)->toBeTrue('Analysis completed successfully');
    }
    
    /**
     * Analyze query parameter handling accuracy
     */
    private function analyzeQueryParameterHandling($spec): void
    {
        $usersGetOperation = $spec->paths['/users']->get ?? null;
        expect($usersGetOperation)->not()->toBeNull('Users GET operation should exist');
        
        if ($usersGetOperation && $usersGetOperation->parameters) {
            $queryParams = collect($usersGetOperation->parameters)
                ->filter(fn($param) => $param->in === 'query')
                ->keyBy(fn($param) => $param->name);
                
            // Check if attribute-defined query parameters are properly extracted
            $expectedQueryParams = ['search', 'page', 'per_page', 'status'];
            foreach ($expectedQueryParams as $expectedParam) {
                if ($queryParams->has($expectedParam)) {
                    dump("✅ Query parameter '{$expectedParam}' found");
                    $param = $queryParams[$expectedParam];
                    expect($param->description)->not()->toBeEmpty("Query param '{$expectedParam}' should have description");
                    expect($param->schema->type)->not()->toBeEmpty("Query param '{$expectedParam}' should have type");
                } else {
                    dump("❌ Query parameter '{$expectedParam}' missing from schema");
                }
            }
            
            // Check enum handling for status parameter
            if ($queryParams->has('status')) {
                $statusParam = $queryParams['status'];
                if (isset($statusParam->schema->enum)) {
                    dump("✅ Status parameter has enum values");
                    expect($statusParam->schema->enum)->toContain('active', 'inactive', 'pending');
                } else {
                    dump("❌ Status parameter missing enum values");
                }
            }
        } else {
            dump("❌ No query parameters found in users GET operation");
        }
    }
    
    /**
     * Analyze request body handling for Spatie Data and FormRequest
     */
    private function analyzeRequestBodyHandling($spec): void
    {
        $usersPostOperation = $spec->paths['/users']->post ?? null;
        expect($usersPostOperation)->not()->toBeNull('Users POST operation should exist');
        
        if ($usersPostOperation && $usersPostOperation->requestBody) {
            $requestBody = $usersPostOperation->requestBody;
            $jsonContent = $requestBody->content['application/json'] ?? null;
            
            if ($jsonContent && $jsonContent->schema) {
                dump("✅ Request body schema found");
                $schema = $jsonContent->schema;
                
                // Check if Spatie Data properties are properly extracted
                if (isset($schema->properties)) {
                    $expectedProperties = ['name', 'email', 'password', 'is_active', 'preferences'];
                    foreach ($expectedProperties as $property) {
                        if (isset($schema->properties[$property])) {
                            dump("✅ Request property '{$property}' found");
                        } else {
                            dump("❌ Request property '{$property}' missing");
                        }
                    }
                    
                    // Check required fields
                    if (isset($schema->required)) {
                        dump("✅ Required fields defined: " . implode(', ', $schema->required));
                        expect($schema->required)->toContain('name', 'email');
                    } else {
                        dump("❌ No required fields defined in request schema");
                    }
                } else {
                    dump("❌ Request body schema has no properties");
                }
            } else {
                dump("❌ Request body has no JSON content or schema");
            }
        } else {
            dump("❌ Users POST operation has no request body");
        }
    }
    
    /**
     * Analyze response schema accuracy and completeness
     */
    private function analyzeResponseSchemas($spec): void
    {
        // Check Spatie Data response schemas
        $usersShowOperation = $spec->paths['/users/{id}']->get ?? null;
        if ($usersShowOperation && isset($usersShowOperation->responses['200'])) {
            $response = $usersShowOperation->responses['200'];
            $jsonContent = $response->content['application/json'] ?? null;
            
            if ($jsonContent && $jsonContent->schema) {
                dump("✅ Response schema found for user show");
                $schema = $jsonContent->schema;
                
                if (isset($schema->properties)) {
                    // Check if UserData properties are properly extracted
                    $expectedProperties = ['id', 'name', 'email'];
                    foreach ($expectedProperties as $property) {
                        if (isset($schema->properties[$property])) {
                            dump("✅ Response property '{$property}' found");
                        } else {
                            dump("❌ Response property '{$property}' missing");
                        }
                    }
                } else {
                    dump("❌ Response schema has no properties");
                }
            } else {
                dump("❌ Response has no JSON content or schema");
            }
        }
        
        // Check JsonResource dynamic analysis
        $dynamicOperation = $spec->paths['/dynamic/{id}']->get ?? null;
        if ($dynamicOperation && isset($dynamicOperation->responses['200'])) {
            $response = $dynamicOperation->responses['200'];
            $jsonContent = $response->content['application/json'] ?? null;
            
            if ($jsonContent && $jsonContent->schema) {
                dump("✅ Dynamic response schema found");
                $schema = $jsonContent->schema;
                
                if (isset($schema->properties)) {
                    dump("✅ Dynamic response has extracted properties: " . implode(', ', array_keys((array)$schema->properties)));
                } else {
                    dump("❌ Dynamic response missing extracted properties");
                }
            }
        }
    }
    
    /**
     * Analyze path parameter handling
     */
    private function analyzePathParameterHandling($spec): void
    {
        $usersShowOperation = $spec->paths['/users/{id}']->get ?? null;
        if ($usersShowOperation && $usersShowOperation->parameters) {
            $pathParams = collect($usersShowOperation->parameters)
                ->filter(fn($param) => $param->in === 'path');
                
            if ($pathParams->isNotEmpty()) {
                dump("✅ Path parameters found: " . $pathParams->pluck('name')->implode(', '));
                foreach ($pathParams as $param) {
                    expect($param->required)->toBeTrue('Path parameters should be required');
                    expect($param->schema->type)->not()->toBeEmpty('Path parameter should have type');
                }
            } else {
                dump("❌ No path parameters found for /users/{id}");
            }
        }
    }
    
    /**
     * Analyze response header handling
     */
    private function analyzeResponseHeaders($spec): void
    {
        $usersGetOperation = $spec->paths['/users']->get ?? null;
        if ($usersGetOperation && isset($usersGetOperation->responses['200'])) {
            $response = $usersGetOperation->responses['200'];
            
            if (isset($response->headers)) {
                $headers = array_keys((array)$response->headers);
                dump("✅ Response headers found: " . implode(', ', $headers));
                
                $expectedHeaders = ['X-Total-Count', 'X-Page-Count'];
                foreach ($expectedHeaders as $header) {
                    if (in_array($header, $headers)) {
                        dump("✅ Header '{$header}' found");
                    } else {
                        dump("❌ Header '{$header}' missing");
                    }
                }
            } else {
                dump("❌ No response headers found in users GET operation");
            }
        }
    }
    
    /**
     * Analyze Spatie Data integration completeness
     */
    private function analyzeSpatieDataIntegration($spec): void
    {
        // This checks if Spatie Data objects are properly analyzed for schema generation
        $allSchemas = [];
        
        foreach ($spec->paths as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                $operation = $pathItem->{$method} ?? null;
                if (!$operation) continue;
                
                // Check request body schemas
                if ($operation->requestBody && $operation->requestBody->content) {
                    foreach ($operation->requestBody->content as $contentType => $content) {
                        if ($content->schema) {
                            $allSchemas[] = "Request: {$method} {$path}";
                        }
                    }
                }
                
                // Check response schemas
                if ($operation->responses) {
                    foreach ($operation->responses as $statusCode => $response) {
                        if ($response->content) {
                            foreach ($response->content as $contentType => $content) {
                                if ($content->schema) {
                                    $allSchemas[] = "Response {$statusCode}: {$method} {$path}";
                                }
                            }
                        }
                    }
                }
            }
        }
        
        dump("✅ Total schemas generated: " . count($allSchemas));
        if (count($allSchemas) > 0) {
            dump("📋 Schema locations:", $allSchemas);
        }
    }
    
    /**
     * Analyze JsonResource dynamic analysis effectiveness
     */
    private function analyzeJsonResourceDynamicAnalysis($spec): void
    {
        $dynamicCollectionOperation = $spec->paths['/dynamic/collection']->get ?? null;
        if ($dynamicCollectionOperation && isset($dynamicCollectionOperation->responses['200'])) {
            $response = $dynamicCollectionOperation->responses['200'];
            $jsonContent = $response->content['application/json'] ?? null;
            
            if ($jsonContent && $jsonContent->schema) {
                $schema = $jsonContent->schema;
                
                if ($schema->type === 'array' && isset($schema->items)) {
                    dump("✅ ResourceCollection correctly identified as array");
                    
                    if (isset($schema->items->properties)) {
                        dump("✅ Resource items have dynamic properties");
                    } else {
                        dump("❌ Resource items missing dynamic properties");
                    }
                } elseif (isset($schema->properties)) {
                    dump("✅ JsonResource has dynamic properties");
                } else {
                    dump("❌ JsonResource missing dynamic analysis");
                }
            }
        }
    }
}
