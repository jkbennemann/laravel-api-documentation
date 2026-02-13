<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Plugins\CodeSamplePlugin;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\RegisterController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\StatusController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class CodeSampleGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable code samples for all tests
        config()->set('api-documentation.code_samples.enabled', true);
        config()->set('api-documentation.code_samples.base_url', 'https://api.example.com');
        config()->set('api-documentation.code_samples.languages', ['bash', 'javascript', 'php', 'python']);
    }

    private function generateSpec(): array
    {
        // Re-build all singletons that depend on config
        $this->refreshPluginRegistry();

        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    private function refreshPluginRegistry(): void
    {
        $this->app->forgetInstance(\JkBennemann\LaravelApiDocumentation\PluginRegistry::class);
        $this->app->forgetInstance(\JkBennemann\LaravelApiDocumentation\Analyzers\AnalysisPipeline::class);
        $this->app->forgetInstance(OpenApiEmitter::class);
    }

    public function test_get_endpoint_has_code_samples(): void
    {
        Route::get('api/status', StatusController::class);

        $spec = $this->generateSpec();

        $op = $spec['paths']['/api/status']['get'] ?? null;
        expect($op)->not()->toBeNull();
        expect($op)->toHaveKey('x-codeSamples');

        $samples = $op['x-codeSamples'];
        expect($samples)->toHaveCount(4);

        $langs = array_column($samples, 'lang');
        expect($langs)->toContain('Shell');
        expect($langs)->toContain('JavaScript');
        expect($langs)->toContain('PHP');
        expect($langs)->toContain('Python');
    }

    public function test_curl_sample_has_correct_method_and_url(): void
    {
        Route::get('api/status', StatusController::class);

        $spec = $this->generateSpec();

        $samples = $spec['paths']['/api/status']['get']['x-codeSamples'];
        $curl = collect($samples)->firstWhere('lang', 'Shell');

        expect($curl['source'])->toContain('curl -X GET');
        expect($curl['source'])->toContain('https://api.example.com/api/status');
    }

    public function test_post_endpoint_includes_request_body_in_samples(): void
    {
        Route::post('api/register', [RegisterController::class, 'store']);

        $spec = $this->generateSpec();

        $samples = $spec['paths']['/api/register']['post']['x-codeSamples'];

        // cURL should have -d and Content-Type
        $curl = collect($samples)->firstWhere('lang', 'Shell');
        expect($curl['source'])->toContain('Content-Type: application/json');
        expect($curl['source'])->toContain('-d');

        // JavaScript should have body and Content-Type
        $js = collect($samples)->firstWhere('lang', 'JavaScript');
        expect($js['source'])->toContain('body: JSON.stringify');
        expect($js['source'])->toContain("'Content-Type': 'application/json'");

        // PHP should have json option
        $php = collect($samples)->firstWhere('lang', 'PHP');
        expect($php['source'])->toContain("'json' =>");

        // Python should have json= parameter
        $python = collect($samples)->firstWhere('lang', 'Python');
        expect($python['source'])->toContain('json=payload');
    }

    public function test_authenticated_endpoint_includes_auth_header(): void
    {
        Route::get('api/posts', [PostController::class, 'index'])->middleware('auth:sanctum');

        $spec = $this->generateSpec();

        $samples = $spec['paths']['/api/posts']['get']['x-codeSamples'];

        $curl = collect($samples)->firstWhere('lang', 'Shell');
        expect($curl['source'])->toContain('Authorization: Bearer YOUR_API_TOKEN');

        $js = collect($samples)->firstWhere('lang', 'JavaScript');
        expect($js['source'])->toContain("'Authorization': 'Bearer YOUR_API_TOKEN'");
    }

    public function test_path_parameters_replaced_in_url(): void
    {
        Route::get('api/posts/{post}', [PostController::class, 'show']);

        $spec = $this->generateSpec();

        $samples = $spec['paths']['/api/posts/{post}']['get']['x-codeSamples'];
        $curl = collect($samples)->firstWhere('lang', 'Shell');

        expect($curl['source'])->toContain('https://api.example.com/api/posts/:post');
    }

    public function test_custom_languages_config(): void
    {
        config()->set('api-documentation.code_samples.languages', ['bash', 'python']);

        Route::get('api/status', StatusController::class);

        $spec = $this->generateSpec();

        $samples = $spec['paths']['/api/status']['get']['x-codeSamples'];
        expect($samples)->toHaveCount(2);

        $langs = array_column($samples, 'lang');
        expect($langs)->toContain('Shell');
        expect($langs)->toContain('Python');
        expect($langs)->not()->toContain('JavaScript');
        expect($langs)->not()->toContain('PHP');
    }

    public function test_code_samples_disabled_by_default(): void
    {
        config()->set('api-documentation.code_samples.enabled', false);

        Route::get('api/status', StatusController::class);

        $spec = $this->generateSpec();

        $op = $spec['paths']['/api/status']['get'] ?? null;
        expect($op)->not()->toBeNull();
        expect($op)->not()->toHaveKey('x-codeSamples');
    }

    public function test_code_sample_plugin_standalone(): void
    {
        $plugin = new CodeSamplePlugin(['bash'], 'https://test.com');

        $operation = [
            'summary' => 'Test',
            'requestBody' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'example' => 'John'],
                                'email' => ['type' => 'string', 'example' => 'john@example.com'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $ctx = new \JkBennemann\LaravelApiDocumentation\Data\AnalysisContext(
            route: new \JkBennemann\LaravelApiDocumentation\Data\RouteInfo(
                uri: 'api/users',
                methods: ['POST'],
                controller: null,
                action: null,
                middleware: [],
                domain: null,
                pathParameters: [],
                name: null,
            ),
        );

        $result = $plugin->transform($operation, $ctx);

        expect($result)->toHaveKey('x-codeSamples');
        expect($result['x-codeSamples'])->toHaveCount(1);
        expect($result['x-codeSamples'][0]['source'])->toContain('curl -X POST');
        expect($result['x-codeSamples'][0]['source'])->toContain('John');
        expect($result['x-codeSamples'][0]['source'])->toContain('john@example.com');
    }
}
