<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Emission;

use JkBennemann\LaravelApiDocumentation\Analyzers\AnalysisPipeline;
use JkBennemann\LaravelApiDocumentation\Attributes\Tag;
use JkBennemann\LaravelApiDocumentation\Data\AnalysisContext;
use JkBennemann\LaravelApiDocumentation\Data\SchemaObject;
use JkBennemann\LaravelApiDocumentation\Merge\ResultMerger;
use JkBennemann\LaravelApiDocumentation\Schema\ExampleGenerator;
use JkBennemann\LaravelApiDocumentation\Schema\PhpDocParser;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;

class OpenApiEmitter
{
    private PathBuilder $pathBuilder;

    private SecurityBuilder $securityBuilder;

    private ResultMerger $merger;

    /** @var array<string, array{name: string, description?: string}> Collected tags keyed by name */
    private array $tags = [];

    private ExampleGenerator $exampleGenerator;

    public function __construct(
        private readonly AnalysisPipeline $pipeline,
        private readonly SchemaRegistry $registry,
        array $config = [],
        ?PhpDocParser $phpDocParser = null,
    ) {
        $this->exampleGenerator = new ExampleGenerator;
        $this->pathBuilder = new PathBuilder($this->exampleGenerator);
        if ($phpDocParser !== null) {
            $this->pathBuilder->setPhpDocParser($phpDocParser);
        }
        $this->securityBuilder = new SecurityBuilder($registry);
        $this->merger = new ResultMerger($config['analysis']['priority'] ?? 'static_first');
    }

    /**
     * Generate the full OpenAPI specification with progress callback.
     *
     * @param  AnalysisContext[]  $contexts
     * @param  array<string, mixed>  $config
     * @param  callable(string): void  $onProgress
     * @return array<string, mixed>
     */
    public function emitWithProgress(array $contexts, array $config, callable $onProgress): array
    {
        return $this->emit($contexts, $config, $onProgress);
    }

    /**
     * Generate the full OpenAPI specification from analyzed routes.
     *
     * @param  AnalysisContext[]  $contexts
     * @param  array<string, mixed>  $config  Domain-specific configuration
     * @param  callable(string): void|null  $onProgress
     * @return array<string, mixed>
     */
    public function emit(array $contexts, array $config = [], ?callable $onProgress = null): array
    {
        // Set OpenAPI version for schema serialization (3.0.x vs 3.1.x nullable syntax)
        SchemaObject::$openApiVersion = $config['open_api_version'] ?? '3.1.0';

        $this->tags = [];
        $spec = $this->buildBase($config);
        $paths = [];

        foreach ($contexts as $ctx) {
            $method = strtolower($ctx->route->httpMethod());
            $path = '/'.ltrim($ctx->route->uri, '/');

            if ($onProgress !== null) {
                $onProgress(strtoupper($method).' '.$path);
            }

            // Run analysis pipeline
            $requestBody = $this->pipeline->extractRequestBody($ctx);
            $responses = $this->pipeline->extractResponses($ctx);
            $queryParams = $this->pipeline->extractQueryParameters($ctx);
            $security = $this->pipeline->detectSecurity($ctx);

            // Build operation
            $operation = $this->pathBuilder->buildOperation(
                ctx: $ctx,
                requestBody: $requestBody,
                queryParams: $queryParams,
                responses: $responses,
                security: $this->securityBuilder->buildOperationSecurity($security),
            );

            // Apply operation transformers
            $operation = $this->pipeline->transformOperation($operation, $ctx);

            // Collect tags (with optional description from #[Tag] attribute)
            $tagAttr = $ctx->getAttribute(Tag::class);
            $attrDescription = ($tagAttr instanceof Tag) ? $tagAttr->description : null;
            foreach ($operation['tags'] ?? [] as $tagName) {
                if (! isset($this->tags[$tagName])) {
                    $entry = ['name' => $tagName];
                    if ($attrDescription !== null) {
                        $entry['description'] = $attrDescription;
                    }
                    $this->tags[$tagName] = $entry;
                } elseif ($attrDescription !== null && ! isset($this->tags[$tagName]['description'])) {
                    $this->tags[$tagName]['description'] = $attrDescription;
                }
            }

            // Add to paths
            if (! isset($paths[$path])) {
                $paths[$path] = [];
            }
            $paths[$path][$method] = $operation;
        }

        // Sort paths alphabetically
        ksort($paths);
        $spec['paths'] = $paths;

        // Add components (generate examples on component schemas first)
        $components = $this->registry->getComponents();
        if (! $components->isEmpty()) {
            foreach ($components->schemas as $name => $schema) {
                $components->schemas[$name] = $this->exampleGenerator->generate($schema);
            }
            $spec['components'] = $components->jsonSerialize();
        }

        // Add tags
        $tags = $this->buildTags($config);
        if (! empty($tags)) {
            $spec['tags'] = $tags;
        }

        // Add x-tagGroups (optionally append catch-all group for ungrouped tags)
        $tagGroups = $config['tag_groups'] ?? [];
        if (! empty($tagGroups)) {
            if ($config['tag_groups_include_ungrouped'] ?? true) {
                $groupedTags = array_merge(...array_column($tagGroups, 'tags'));
                $allTagNames = array_column($tags, 'name');
                $ungrouped = array_values(array_diff($allTagNames, $groupedTags));

                if (! empty($ungrouped)) {
                    $tagGroups[] = ['name' => 'Other', 'tags' => $ungrouped];
                }
            }

            $spec['x-tagGroups'] = $tagGroups;
        }

        // Add externalDocs
        $externalDocs = $config['external_docs'] ?? null;
        if (is_array($externalDocs) && isset($externalDocs['url'])) {
            $spec['externalDocs'] = array_filter($externalDocs, fn ($v) => $v !== null);
        }

        return $spec;
    }

    /**
     * Build the tags array from collected operation tags, config descriptions, and trait tags.
     *
     * Precedence: config 'tags' > #[Tag] attribute description > no description.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    private function buildTags(array $config): array
    {
        $configDescriptions = $config['tags'] ?? [];

        // Merge config descriptions into collected tags (config overrides attribute)
        foreach ($this->tags as $name => &$entry) {
            if (isset($configDescriptions[$name])) {
                $entry['description'] = $configDescriptions[$name];
            }
        }
        unset($entry);

        // Add config-only tags that weren't collected from operations
        foreach ($configDescriptions as $name => $description) {
            if (! isset($this->tags[$name])) {
                $this->tags[$name] = array_filter(
                    ['name' => $name, 'description' => $description],
                    fn ($v) => $v !== null,
                );
            }
        }

        // Add trait tags with x-traitTag: true
        foreach ($config['trait_tags'] ?? [] as $traitTag) {
            $entry = ['name' => $traitTag['name'], 'x-traitTag' => true];
            if (isset($traitTag['description'])) {
                $entry['description'] = $traitTag['description'];
            }
            $this->tags[$traitTag['name']] = $entry;
        }

        // Sort by name and return indexed array
        ksort($this->tags);

        // Clean null values from each tag entry
        return array_values(array_map(
            fn (array $tag) => array_filter($tag, fn ($v) => $v !== null),
            $this->tags,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBase(array $config): array
    {
        $spec = [
            'openapi' => $config['open_api_version'] ?? '3.1.0',
            'info' => [
                'title' => $config['title'] ?? config('api-documentation.title', 'API Documentation'),
                'version' => $config['version'] ?? config('api-documentation.version', '1.0.0'),
            ],
        ];

        // Description
        $description = $config['description'] ?? null;
        if ($description !== null) {
            $spec['info']['description'] = $description;
        }

        // Terms of service (supports both snake_case and legacy camelCase)
        $tos = $config['terms_of_service'] ?? $config['termsOfService'] ?? null;
        if ($tos !== null) {
            $spec['info']['termsOfService'] = $tos;
        }

        // Contact
        $contact = $config['contact'] ?? null;
        if (is_array($contact)) {
            $spec['info']['contact'] = $contact;
        }

        // Servers
        $servers = $config['servers'] ?? config('api-documentation.servers', []);
        if (! empty($servers)) {
            $spec['servers'] = array_map(fn ($s) => [
                'url' => $s['url'],
                'description' => $s['description'] ?? null,
            ], $servers);
            // Clean nulls
            $spec['servers'] = array_map(
                fn ($s) => array_filter($s, fn ($v) => $v !== null),
                $spec['servers']
            );
        }

        return $spec;
    }
}
