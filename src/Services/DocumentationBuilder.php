<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JkBennemann\LaravelApiDocumentation\Exceptions\DocumentationException;
use openapiphp\openapi\Writer;
use Throwable;

class DocumentationBuilder
{
    public function __construct(
        private RouteComposition $routeService,
        private OpenApi $openApiService,
        private ?CapturedResponseRepository $capturedResponses = null
    ) {
        // Inject repository if capture is enabled
        if ($this->capturedResponses === null && config('api-documentation.generation.use_captured', true)) {
            $this->capturedResponses = app(CapturedResponseRepository::class);
        }
    }

    public function build(string $filename, ?string $name = null, ?string $docName = null, bool $includeDev = false): iterable
    {
        if (! isset($name)) {
            $name = $filename;
        }

        $this->setSwaggerDetails($docName, $includeDev);

        $routesData = $this->routeService->process($docName);

        yield count($routesData).' routes generated for '.$name;

        // Enhance routes with captured response data
        if ($this->capturedResponses) {
            $routesData = $this->enhanceRoutesWithCapturedData($routesData);
        }

        try {
            $openApi = $this->openApiService->processRoutes($routesData)->get();
            $json = Writer::writeToJson($openApi);
            $path = $this->getPath($filename);
            $success = File::put($path, $json);
            if ($success === false) {
                throw new DocumentationException('Could not write to file.');
            }

            yield "Generation for {$name} completed.";
        } catch (Throwable $e) {

            throw new DocumentationException("Error writing documentation to file '{$filename}': {$e->getMessage()}");
        }
    }

    private function getPath(string $filename): string
    {
        if (Str::endsWith($filename, '.json') === false) {
            $filename .= '.json';
        }

        return Storage::disk(config('api-documentation.ui.storage.disk', 'public'))
            ->path($filename);
    }

    private function setSwaggerDetails($docName, bool $includeDev = false)
    {
        // Only apply domain-specific config if docName is explicitly provided
        if ($docName) {
            $prefix = 'api-documentation.domains.'.$docName;

            if (config($prefix)) {
                // Mark that domain config is being applied to prevent override
                $this->openApiService->setDomainConfigApplied(true);

                if (config($prefix.'.servers')) {
                    $servers = config($prefix.'.servers');

                    // Filter out development servers unless --dev flag is used
                    if (!$includeDev) {
                        $servers = array_filter($servers, function ($server) {
                            return !($server['development'] ?? false);
                        });
                        // Re-index array to avoid gaps
                        $servers = array_values($servers);
                    }

                    $this->openApiService->get()->servers = $servers;
                }
                if (config($prefix.'.title')) {
                    $this->openApiService->get()->info->title = config($prefix.'.title');
                }
                if (config($prefix.'.description')) {
                    $description = config($prefix.'.description');

                    // Append alternative UI links if enabled
                    if (config($prefix.'.append_alternative_uis', false)) {
                        // Use domain-specific default UI if available, otherwise use global default
                        $defaultUi = config($prefix.'.default_ui', config('api-documentation.ui.default', 'swagger'));
                        $description = $this->appendAlternativeUiLinks($description, $defaultUi);
                    }

                    $this->openApiService->get()->info->description = $description;
                }
                if (config($prefix.'.termsOfService')) {
                    $this->openApiService->get()->info->termsOfService = config($prefix.'.termsOfService');
                }
                if (config($prefix.'.contact')) {
                    $contactConfig = config($prefix.'.contact');
                    if (is_array($contactConfig)) {
                        $contact = new \openapiphp\openapi\spec\Contact($contactConfig);
                        $this->openApiService->get()->info->contact = $contact;
                    }
                }
            }
        }
    }

    /**
     * Enhance routes with captured response data
     */
    private function enhanceRoutesWithCapturedData(array $routesData): array
    {
        $strategy = config('api-documentation.generation.merge_strategy', 'captured_priority');
        $warnMissing = config('api-documentation.generation.warn_missing_captures', true);

        foreach ($routesData as &$route) {
            $uri = $route['uri'] ?? $route['route'] ?? '';
            $method = $route['method'] ?? 'GET';

            // Get captured responses for this route
            $captured = $this->capturedResponses->getForRoute($uri, $method);

            if (!$captured) {
                if ($warnMissing) {
                    logger()->debug("No captured response for {$method} {$uri}");
                }
                continue;
            }

            // Apply merge strategy
            if ($strategy === 'captured_priority') {
                // Convert captured data to OpenAPI format and override route responses
                if (!isset($route['responses'])) {
                    $route['responses'] = [];
                }

                foreach ($captured as $statusCode => $capturedResponse) {
                    // Convert captured schema to OpenAPI format expected by processOperationResponses
                    $route['responses'][$statusCode] = $this->convertCapturedToOpenApiFormat($capturedResponse);
                }

                $route['has_captured_data'] = true;
            }
        }

        return $routesData;
    }

    /**
     * Convert captured response format to OpenAPI format
     */
    private function convertCapturedToOpenApiFormat(array $capturedResponse): array
    {
        $statusCode = $capturedResponse['status'];
        $schema = $capturedResponse['schema'] ?? ['type' => 'object'];

        // Get content type from captured headers or default to JSON
        $contentType = $capturedResponse['headers']['content-type'] ??
                      $capturedResponse['headers']['Content-Type'] ??
                      'application/json';

        $openApiResponse = [
            'type' => $schema['type'] ?? 'object',
            'description' => $this->getDescriptionForStatus($statusCode),
            'content_type' => $contentType,
        ];

        // Add properties if they exist in the schema
        if (isset($schema['properties'])) {
            $openApiResponse['properties'] = $schema['properties'];
        }

        // Add items if it's an array type
        if (isset($schema['items'])) {
            $openApiResponse['items'] = $schema['items'];
        }

        // Add required fields if they exist
        if (isset($schema['required'])) {
            $openApiResponse['required'] = $schema['required'];
        }

        // Add example if available
        if (isset($capturedResponse['example'])) {
            $openApiResponse['example'] = $capturedResponse['example'];
        }

        return $openApiResponse;
    }

    /**
     * Get description for HTTP status code
     */
    private function getDescriptionForStatus(int $statusCode): string
    {
        return match ($statusCode) {
            200 => 'Success',
            201 => 'Created',
            202 => 'Accepted',
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

    /**
     * Append alternative UI links to description based on enabled UIs
     */
    private function appendAlternativeUiLinks(string $description, string $defaultUi = null): string
    {
        $allUis = [];

        // Get default UI (use provided or fall back to global config)
        if ($defaultUi === null) {
            $defaultUi = config('api-documentation.ui.default', 'swagger');
        }

        // Check each UI type
        $uiTypes = ['swagger', 'redoc', 'scalar'];

        foreach ($uiTypes as $uiType) {
            // Check if this UI is enabled
            if (config("api-documentation.ui.{$uiType}.enabled", false)) {
                $route = config("api-documentation.ui.{$uiType}.route");
                $label = ucfirst($uiType);

                // Mark the default UI
                if ($uiType === $defaultUi) {
                    $label .= ' (current)';
                }

                $allUis[] = "<a href=\"{$route}\">{$label}</a>";
            }
        }

        // Only append if we have more than one UI enabled (so there are alternatives)
        if (count($allUis) > 1) {
            $links = implode(', ', $allUis);
            $description .= "<br /><br />View this documentation in different formats: {$links}.";
        }

        return $description;
    }
}
