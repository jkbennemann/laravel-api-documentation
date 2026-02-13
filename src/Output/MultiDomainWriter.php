<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Output;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MultiDomainWriter
{
    private JsonWriter $jsonWriter;

    private YamlWriter $yamlWriter;

    private PostmanCollectionWriter $postmanWriter;

    public function __construct()
    {
        $this->jsonWriter = new JsonWriter;
        $this->yamlWriter = new YamlWriter;
        $this->postmanWriter = new PostmanCollectionWriter;
    }

    /**
     * Write a spec to disk using the configured storage.
     */
    public function write(array $spec, string $filename, string $format = 'json'): string
    {
        if (! Str::endsWith($filename, ".{$format}")) {
            $filename .= ".{$format}";
        }

        $path = Storage::disk(config('api-documentation.ui.storage.disk', 'public'))
            ->path($filename);

        if ($format === 'postman') {
            $this->postmanWriter->write($spec, $path);
        } elseif ($format === 'yaml' || $format === 'yml') {
            $this->yamlWriter->write($spec, $path);
        } else {
            $this->jsonWriter->write($spec, $path);
        }

        return $path;
    }

    /**
     * Write spec and return the content string.
     */
    public function toString(array $spec, string $format = 'json'): string
    {
        if ($format === 'postman') {
            return json_encode(
                $this->postmanWriter->convert($spec),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        if ($format === 'yaml' || $format === 'yml') {
            return $this->yamlWriter->toYaml($spec);
        }

        return $this->jsonWriter->toJson($spec);
    }

    /**
     * Append alternative UI links to a domain description.
     */
    public function appendAlternativeUiLinks(string $description, ?string $defaultUi = null): string
    {
        $defaultUi ??= config('api-documentation.ui.default', 'swagger');
        $allUis = [];
        $uiTypes = ['swagger', 'redoc', 'scalar'];

        foreach ($uiTypes as $uiType) {
            if (config("api-documentation.ui.{$uiType}.enabled", false)) {
                $route = config("api-documentation.ui.{$uiType}.route");
                $label = ucfirst($uiType);

                if ($uiType === $defaultUi) {
                    $label .= ' (current)';
                }

                $allUis[] = "<a href=\"{$route}\">{$label}</a>";
            }
        }

        if (count($allUis) > 1) {
            $links = implode(', ', $allUis);
            $description .= "<br /><br />View this documentation in different formats: {$links}.";
        }

        return $description;
    }
}
