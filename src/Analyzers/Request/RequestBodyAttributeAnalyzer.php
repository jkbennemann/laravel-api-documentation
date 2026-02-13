<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Request;

use JkBennemann\LaravelApiDocumentation\Attributes\DataResponse;
use JkBennemann\LaravelApiDocumentation\Attributes\Parameter;
use JkBennemann\LaravelApiDocumentation\Attributes\RequestBody;
use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\Schema\TypeMapper;

class RequestBodyAttributeAnalyzer implements RequestBodyExtractor
{
    private TypeMapper $typeMapper;

    public function __construct()
    {
        $this->typeMapper = new TypeMapper;
    }

    public function extract(AnalysisContext $ctx): ?SchemaResult
    {
        // Check for #[RequestBody] attribute
        $requestBody = $ctx->getAttribute(RequestBody::class);
        if ($requestBody instanceof RequestBody) {
            return $this->processRequestBodyAttribute($requestBody);
        }

        // Check for #[Parameter] attributes on the method
        $parameters = $ctx->getAttributes(Parameter::class);
        if (! empty($parameters)) {
            // Skip if DataResponse attributes exist — Parameters document the response, not request
            if ($ctx->hasAttribute(DataResponse::class)) {
                return null;
            }

            // Skip for read-only HTTP methods (no request body)
            $httpMethod = strtoupper($ctx->route->httpMethod());
            if (in_array($httpMethod, ['GET', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
                return null;
            }

            return $this->processParameterAttributes($parameters);
        }

        return null;
    }

    private function processRequestBodyAttribute(RequestBody $attr): SchemaResult
    {
        $schema = SchemaObject::object();

        if ($attr->dataClass !== null) {
            // Use type mapper to resolve the class
            $schema = $this->typeMapper->mapClassName($attr->dataClass);
        }

        return new SchemaResult(
            schema: $schema,
            description: $attr->description ?: 'Request body',
            contentType: $attr->contentType,
            examples: $attr->example ? ['default' => $attr->example] : [],
            source: 'attribute:RequestBody',
            required: $attr->required,
        );
    }

    /**
     * @param  Parameter[]  $parameters
     */
    private function processParameterAttributes(array $parameters): SchemaResult
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $param) {
            $schema = new SchemaObject(
                type: $param->type,
                format: $param->format,
                description: $param->description ?: null,
                example: $param->example,
                nullable: $param->nullable,
                minLength: $param->minLength,
                maxLength: $param->maxLength,
                deprecated: $param->deprecated,
            );

            $properties[$param->name] = $schema;

            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return new SchemaResult(
            schema: SchemaObject::object($properties, ! empty($required) ? $required : null),
            description: 'Request body',
            source: 'attribute:Parameter',
        );
    }
}
