<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Response;

use JkBennemann\LaravelApiDocumentation\Contracts\ResponseExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\ResponseResult;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Repository\CapturedResponseRepository;

class RuntimeCaptureResponseAnalyzer implements ResponseExtractor
{
    public function __construct(
        private readonly CapturedResponseRepository $repository,
    ) {}

    public function extract(AnalysisContext $ctx): array
    {
        $captured = $this->repository->getForRoute(
            $ctx->route->uri,
            $ctx->route->httpMethod()
        );

        if (empty($captured)) {
            return [];
        }

        $results = [];

        foreach ($captured as $statusCode => $captureData) {
            $statusCode = (int) $statusCode;
            $schema = null;
            $examples = [];

            if (isset($captureData['schema'])) {
                $schema = $this->arrayToSchemaObject($captureData['schema']);
            }

            if (isset($captureData['example'])) {
                $examples['captured'] = $captureData['example'];
            }

            $results[] = new ResponseResult(
                statusCode: $statusCode,
                schema: $schema,
                description: $this->descriptionForStatus($statusCode),
                examples: $examples,
                source: 'runtime_capture',
            );
        }

        return $results;
    }

    private function arrayToSchemaObject(array $data): SchemaObject
    {
        $type = $data['type'] ?? 'object';
        $schema = new SchemaObject(type: $type);

        if (isset($data['format'])) {
            $schema->format = $data['format'];
        }

        if (isset($data['properties'])) {
            $props = [];
            foreach ($data['properties'] as $name => $propData) {
                $props[$name] = $this->arrayToSchemaObject($propData);
            }
            $schema->properties = $props;
        }

        if (isset($data['items'])) {
            $schema->items = $this->arrayToSchemaObject($data['items']);
        }

        if (isset($data['required'])) {
            $schema->required = $data['required'];
        }

        if (isset($data['enum'])) {
            $schema->enum = $data['enum'];
        }

        if (isset($data['nullable'])) {
            $schema->nullable = $data['nullable'];
        }

        return $schema;
    }

    private function descriptionForStatus(int $status): string
    {
        return match ($status) {
            200 => 'Success',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            default => 'Response',
        };
    }
}
