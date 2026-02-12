<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use JkBennemann\LaravelApiDocumentation\Services\RequestAnalyzer;
use JkBennemann\LaravelApiDocumentation\Services\ResponseAnalyzer;
use openapiphp\openapi\spec\MediaType;
use openapiphp\openapi\spec\Parameter;
use openapiphp\openapi\spec\RequestBody;
use openapiphp\openapi\spec\Response;
use openapiphp\openapi\spec\Schema;
use ReflectionClass;
use ReflectionMethod;

trait HandlesSmartFeatures
{
    private RequestAnalyzer $requestAnalyzer;

    private ResponseAnalyzer $responseAnalyzer;

    private bool $enabled;

    private function initializeSmartFeatures(): void
    {
        $this->requestAnalyzer = app(RequestAnalyzer::class);
        $this->responseAnalyzer = app(ResponseAnalyzer::class);
        $this->enabled = config('api-documentation.smart_features', true);
    }

    private function isSmartFeaturesEnabled(): bool
    {
        return $this->enabled;
    }

    private function generateSmartRequestBody(string $controller, string $action): ?RequestBody
    {
        try {
            $reflection = new ReflectionClass($controller);
            $method = $reflection->getMethod($action);

            // Check for validate method call
            $validationRules = $this->extractValidationRules($method);
            if (! empty($validationRules)) {
                return new RequestBody([
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $this->convertValidationRulesToSchema($validationRules),
                        ],
                    ],
                ]);
            }
        } catch (Throwable $e) {
            error_log('Error generating smart request body: '.$e->getMessage());
        }

        return null;
    }

    private function extractValidationRules(ReflectionMethod $method): array
    {
        try {
            $source = file_get_contents($method->getFileName());
            $startLine = $method->getStartLine() - 1;
            $endLine = $method->getEndLine() - 1;
            $length = $endLine - $startLine;

            $methodSource = implode("\n", array_slice(
                explode("\n", $source),
                $startLine,
                $length
            ));

            // Extract validation rules from validate method call
            if (preg_match('/\$request->validate\(\s*\[(.*?)\]\s*\)/s', $methodSource, $matches)) {
                $rulesString = $matches[1];
                $rules = [];

                // Parse the rules string into an associative array
                preg_match_all('/\'([^\']+)\'\s*=>\s*\'([^\']+)\'/', $rulesString, $ruleMatches);
                foreach ($ruleMatches[1] as $i => $field) {
                    $rules[$field] = explode('|', $ruleMatches[2][$i]);
                }

                return $rules;
            }
        } catch (Throwable $e) {
            error_log('Error extracting validation rules: '.$e->getMessage());
        }

        return [];
    }

    private function convertValidationRulesToSchema(array $rules): Schema
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            $type = 'string';
            $format = null;
            $isRequired = false;

            foreach ($fieldRules as $rule) {
                switch ($rule) {
                    case 'required':
                        $isRequired = true;
                        break;
                    case 'integer':
                    case 'numeric':
                        $type = 'integer';
                        break;
                    case 'boolean':
                        $type = 'boolean';
                        break;
                    case 'array':
                        $type = 'array';
                        break;
                    case 'email':
                        $format = 'email';
                        break;
                    case 'date':
                        $format = 'date';
                        break;
                    case 'datetime':
                        $format = 'date-time';
                        break;
                }
            }

            if ($isRequired) {
                $required[] = $field;
            }

            $property = ['type' => $type];
            if ($format) {
                $property['format'] = $format;
            }

            $properties[$field] = $property;
        }

        return new Schema([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ]);
    }

    private function generateSmartResponse(ReflectionMethod $method): array
    {
        $responses = [];

        try {
            // Check for validation rules to add 422 response
            $validationRules = $this->extractValidationRules($method);
            if (! empty($validationRules)) {
                $responses['422'] = new Response([
                    'description' => 'Validation failed',
                    'content' => [
                        'application/json' => [
                            'schema' => new Schema([
                                'type' => 'object',
                                'properties' => [
                                    'message' => ['type' => 'string'],
                                    'errors' => [
                                        'type' => 'object',
                                        'additionalProperties' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ]);
            }

            // Check return type for 200 response
            $returnType = $method->getReturnType();
            if ($returnType) {
                $typeName = $returnType->getName();
                if ($typeName === JsonResponse::class) {
                    $responses['200'] = new Response([
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => new Schema(['type' => 'object']),
                            ],
                        ],
                    ]);
                } elseif ($typeName === Collection::class) {
                    $responses['200'] = new Response([
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => new Schema([
                                    'type' => 'array',
                                    'items' => ['type' => 'object'],
                                ]),
                            ],
                        ],
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('Error generating smart response: '.$e->getMessage());
        }

        return $responses;
    }

    private function generateRequestBody(ReflectionMethod $method): ?RequestBody
    {
        if (! $this->enabled) {
            return null;
        }

        $requestClass = $this->findRequestClass($method);
        if (! $requestClass) {
            return null;
        }

        $parameters = $this->requestAnalyzer->analyzeRequest($requestClass);
        if (empty($parameters)) {
            return null;
        }

        $schema = new Schema([
            'type' => 'object',
            'properties' => $parameters,
        ]);

        return new RequestBody([
            'required' => true,
            'content' => [
                'application/json' => new MediaType([
                    'schema' => $schema,
                ]),
            ],
        ]);
    }

    private function generateResponse(ReflectionMethod $method): array
    {
        if (! $this->enabled) {
            return [];
        }

        $responses = [];

        // Add success response
        $successResponse = $this->responseAnalyzer->analyzeResponse($method);
        if ($successResponse) {
            $responses['200'] = new Response([
                'description' => 'Successful response',
                'content' => [
                    'application/json' => new MediaType([
                        'schema' => $successResponse,
                    ]),
                ],
            ]);
        }

        // Add error responses
        $errorResponses = $this->responseAnalyzer->analyzeErrorResponses($method);
        foreach ($errorResponses as $code => $schema) {
            $responses[$code] = new Response([
                'description' => 'Error response',
                'content' => [
                    'application/json' => new MediaType([
                        'schema' => $schema,
                    ]),
                ],
            ]);
        }

        return $responses;
    }

    private function generateParameters(ReflectionMethod $method): array
    {
        if (! $this->enabled) {
            return [];
        }

        $parameters = [];
        $routeParameters = $this->requestAnalyzer->analyzeRouteParameters($method);

        foreach ($routeParameters as $name => $schema) {
            $parameters[] = new Parameter([
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $schema,
            ]);
        }

        return $parameters;
    }

    private function findRequestClass(ReflectionMethod $method): ?string
    {
        $parameters = $method->getParameters();
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if (! $type) {
                continue;
            }

            $typeName = $type->getName();
            if (is_a($typeName, Request::class, true)) {
                return $typeName;
            }
        }

        return null;
    }

    /**
     * Generate response documentation for a controller method
     */
    protected function generateResponseDocumentation(string $controller, string $method, string $httpMethod): array
    {
        $this->initializeSmartFeatures();

        try {
            $responseStructure = $this->responseAnalyzer->analyzeMethodResponse($controller, $method);

            if (! empty($responseStructure)) {
                $successCode = $this->getSuccessCodeForMethod($httpMethod);

                return [
                    $successCode => new Response([
                        'description' => $this->getSuccessDescriptionForMethod($httpMethod),
                        'content' => [
                            'application/json' => new MediaType([
                                'schema' => new Schema($responseStructure),
                            ]),
                        ],
                    ]),
                ];
            }
        } catch (\Throwable) {
            // Silently fail and return empty array
        }

        return [];
    }

    /**
     * Get success HTTP code for method
     */
    private function getSuccessCodeForMethod(string $method): string
    {
        return match (strtoupper($method)) {
            'POST' => '201',
            'DELETE' => '204',
            default => '200',
        };
    }

    /**
     * Get success description for method
     */
    private function getSuccessDescriptionForMethod(string $method): string
    {
        return match (strtoupper($method)) {
            'GET' => 'Successful response',
            'POST' => 'Resource created successfully',
            'PUT', 'PATCH' => 'Resource updated successfully',
            'DELETE' => 'Resource deleted successfully',
            default => 'Successful operation',
        };
    }
}
