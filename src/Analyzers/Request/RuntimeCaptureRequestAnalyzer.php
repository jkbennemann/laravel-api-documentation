<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Analyzers\Request;

use JkBennemann\LaravelApiDocumentation\Contracts\RequestBodyExtractor;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Data\SchemaResult;
use JkBennemann\LaravelApiDocumentation\Repository\CapturedResponseRepository;

class RuntimeCaptureRequestAnalyzer implements RequestBodyExtractor
{
    public function __construct(
        private readonly CapturedResponseRepository $repository,
    ) {}

    public function extract(AnalysisContext $ctx): ?SchemaResult
    {
        $captured = $this->repository->getForRoute(
            $ctx->route->uri,
            $ctx->route->httpMethod()
        );

        if (empty($captured)) {
            return null;
        }

        // Look for request body schema in captured data
        foreach ($captured as $statusCode => $captureData) {
            $request = $captureData['request'] ?? null;
            if ($request === null) {
                continue;
            }

            $bodySchema = $request['body_schema'] ?? null;
            if ($bodySchema === null) {
                continue;
            }

            $schema = $this->arrayToSchemaObject($bodySchema);
            $examples = [];

            if (isset($request['body'])) {
                $examples['captured'] = $request['body'];
            }

            return new SchemaResult(
                schema: $schema,
                description: 'Request body (from captured data)',
                examples: $examples,
                source: 'runtime_capture',
            );
        }

        return null;
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
}
