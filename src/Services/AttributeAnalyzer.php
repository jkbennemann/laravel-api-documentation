<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use JkBennemann\LaravelApiDocumentation\Attributes\QueryParameter;
use JkBennemann\LaravelApiDocumentation\Attributes\RequestBody;
use JkBennemann\LaravelApiDocumentation\Attributes\ResponseBody;
use JkBennemann\LaravelApiDocumentation\Attributes\ResponseHeader;
use ReflectionMethod;

class AttributeAnalyzer
{
    /**
     * Extract query parameters from method attributes
     */
    public function extractQueryParameters(ReflectionMethod $method): array
    {
        $parameters = [];
        $queryParamAttributes = $method->getAttributes(QueryParameter::class);

        foreach ($queryParamAttributes as $attribute) {
            /** @var QueryParameter $queryParam */
            $queryParam = $attribute->newInstance();

            $parameters[$queryParam->name] = [
                'type' => $queryParam->type,
                'format' => $queryParam->format,
                'description' => $queryParam->description,
                'required' => $queryParam->required,
                'example' => $queryParam->example,
                'enum' => $queryParam->enum,
            ];
        }

        return $parameters;
    }

    /**
     * Extract request body configuration from method attributes
     */
    public function extractRequestBody(ReflectionMethod $method): ?array
    {
        $requestBodyAttributes = $method->getAttributes(RequestBody::class);

        if (empty($requestBodyAttributes)) {
            return null;
        }

        /** @var RequestBody $requestBody */
        $requestBody = $requestBodyAttributes[0]->newInstance();

        return [
            'description' => $requestBody->description,
            'content_type' => $requestBody->contentType,
            'required' => $requestBody->required,
            'data_class' => $requestBody->dataClass,
            'example' => $requestBody->example,
        ];
    }

    /**
     * Extract response body configurations from method attributes
     */
    public function extractResponseBodies(ReflectionMethod $method): array
    {
        $responses = [];
        $responseBodyAttributes = $method->getAttributes(ResponseBody::class);

        foreach ($responseBodyAttributes as $attribute) {
            /** @var ResponseBody $responseBody */
            $responseBody = $attribute->newInstance();

            $responses[$responseBody->statusCode] = [
                'description' => $responseBody->description,
                'content_type' => $responseBody->contentType,
                'data_class' => $responseBody->dataClass,
                'example' => $responseBody->example,
                'is_collection' => $responseBody->isCollection,
            ];
        }

        return $responses;
    }

    /**
     * Extract response headers from method attributes
     */
    public function extractResponseHeaders(ReflectionMethod $method): array
    {
        $headers = [];
        $responseHeaderAttributes = $method->getAttributes(ResponseHeader::class);

        foreach ($responseHeaderAttributes as $attribute) {
            /** @var ResponseHeader $responseHeader */
            $responseHeader = $attribute->newInstance();

            $headers[$responseHeader->name] = [
                'description' => $responseHeader->description,
                'type' => $responseHeader->type,
                'format' => $responseHeader->format,
                'example' => $responseHeader->example,
                'required' => $responseHeader->required,
            ];
        }

        return $headers;
    }

    /**
     * Check if method has any API documentation attributes
     */
    public function hasApiDocumentationAttributes(ReflectionMethod $method): bool
    {
        $attributeClasses = [
            QueryParameter::class,
            RequestBody::class,
            ResponseBody::class,
            ResponseHeader::class,
        ];

        foreach ($attributeClasses as $attributeClass) {
            if (! empty($method->getAttributes($attributeClass))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract all API documentation attributes from a method
     */
    public function extractAllAttributes(ReflectionMethod $method): array
    {
        return [
            'query_parameters' => $this->extractQueryParameters($method),
            'request_body' => $this->extractRequestBody($method),
            'response_bodies' => $this->extractResponseBodies($method),
            'response_headers' => $this->extractResponseHeaders($method),
        ];
    }
}
