<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Error;

use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;

class ConfiguredErrorAnalyzer implements ResponseExtractor
{
    private array $config;

    public function __construct(
        array $config = [],
        private ?ExceptionHandlerSchemaAnalyzer $handlerAnalyzer = null,
    ) {
        $this->config = $config['error_responses'] ?? [];
    }

    public function extract(AnalysisContext $ctx): array
    {
        if (empty($this->config) || ! ($this->config['enabled'] ?? true)) {
            return [];
        }

        $results = [];
        $statusMessages = $this->config['defaults']['status_messages'] ?? [];

        // Check for domain-specific error templates
        $controllerName = $ctx->controllerClass() ? class_basename($ctx->controllerClass()) : '';
        $patterns = $this->config['domain_detection']['patterns'] ?? [];

        foreach ($patterns as $pattern => [$domain, $context]) {
            if (fnmatch($pattern, $controllerName)) {
                $domainErrors = $this->config['domains'][$domain][$context] ?? [];
                foreach ($domainErrors as $statusCode => $message) {
                    $results[] = new ResponseResult(
                        statusCode: (int) $statusCode,
                        schema: $this->errorSchema($message, (int) $statusCode),
                        description: is_string($message) ? $message : 'Error',
                        source: 'config:error_responses',
                    );
                }
            }
        }

        return $results;
    }

    private function errorSchema(string|array $message, int $statusCode = 0): SchemaObject
    {
        // Try custom handler envelope first
        $custom = $this->handlerAnalyzer?->getErrorSchema($statusCode);
        if ($custom !== null) {
            return $custom;
        }

        if (is_string($message)) {
            return SchemaObject::object(
                properties: [
                    'message' => new SchemaObject(type: 'string', example: $message),
                ],
                required: ['message'],
            );
        }

        // Array of field-specific messages
        $fieldProps = [];
        foreach ($message as $field => $fieldMessage) {
            $fieldProps[$field] = SchemaObject::array(
                new SchemaObject(type: 'string', example: $fieldMessage)
            );
        }

        return SchemaObject::object(
            properties: [
                'message' => SchemaObject::string(),
                'errors' => SchemaObject::object($fieldProps),
            ],
            required: ['message', 'errors'],
        );
    }
}
