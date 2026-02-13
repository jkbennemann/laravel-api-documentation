<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;
use JkBennemann\LaravelApiDocumentation\Schema\ValidationRuleMapper;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\DocumentController;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\PostController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class FileUploadDetectionTest extends TestCase
{
    private function generateSpec(): array
    {
        app(SchemaRegistry::class)->reset();

        $discovery = app(RouteDiscovery::class);
        $contexts = $discovery->discover();

        $emitter = app(OpenApiEmitter::class);

        return $emitter->emit($contexts, config('api-documentation'));
    }

    public function test_file_upload_sets_multipart_content_type(): void
    {
        Route::post('api/documents', [DocumentController::class, 'store']);

        $spec = $this->generateSpec();

        $requestBody = $spec['paths']['/api/documents']['post']['requestBody'] ?? null;
        expect($requestBody)->not()->toBeNull();
        expect($requestBody['content'])->toHaveKey('multipart/form-data');
        expect($requestBody['content'])->not()->toHaveKey('application/json');
    }

    public function test_file_field_has_binary_format(): void
    {
        Route::post('api/documents', [DocumentController::class, 'store']);

        $spec = $this->generateSpec();

        $schema = $spec['paths']['/api/documents']['post']['requestBody']['content']['multipart/form-data']['schema'];
        $schema = $this->resolveSchemaRef($schema, $spec);
        $documentProp = $schema['properties']['document'] ?? null;

        expect($documentProp)->not()->toBeNull();
        expect($documentProp['type'])->toBe('string');
        expect($documentProp['format'])->toBe('binary');
    }

    public function test_image_field_has_binary_format(): void
    {
        Route::post('api/documents', [DocumentController::class, 'store']);

        $spec = $this->generateSpec();

        $schema = $spec['paths']['/api/documents']['post']['requestBody']['content']['multipart/form-data']['schema'];
        $schema = $this->resolveSchemaRef($schema, $spec);
        $thumbnailProp = $schema['properties']['thumbnail'] ?? null;

        expect($thumbnailProp)->not()->toBeNull();
        expect($thumbnailProp['type'])->toBe('string');
        expect($thumbnailProp['format'])->toBe('binary');
    }

    public function test_mimes_rule_adds_description(): void
    {
        Route::post('api/documents', [DocumentController::class, 'store']);

        $spec = $this->generateSpec();

        $schema = $spec['paths']['/api/documents']['post']['requestBody']['content']['multipart/form-data']['schema'];
        $schema = $this->resolveSchemaRef($schema, $spec);
        $documentProp = $schema['properties']['document'] ?? null;

        expect($documentProp)->not()->toBeNull();
        expect($documentProp['description'])->toContain('pdf');
        expect($documentProp['description'])->toContain('docx');
    }

    public function test_non_file_request_stays_json(): void
    {
        Route::post('api/posts', [PostController::class, 'store']);

        $spec = $this->generateSpec();

        $requestBody = $spec['paths']['/api/posts']['post']['requestBody'] ?? null;
        expect($requestBody)->not()->toBeNull();
        expect($requestBody['content'])->toHaveKey('application/json');
        expect($requestBody['content'])->not()->toHaveKey('multipart/form-data');
    }

    public function test_non_file_fields_coexist_with_file_fields(): void
    {
        Route::post('api/documents', [DocumentController::class, 'store']);

        $spec = $this->generateSpec();

        $schema = $spec['paths']['/api/documents']['post']['requestBody']['content']['multipart/form-data']['schema'];
        $schema = $this->resolveSchemaRef($schema, $spec);

        // title is a regular string field alongside file fields
        $titleProp = $schema['properties']['title'] ?? null;
        expect($titleProp)->not()->toBeNull();
        expect($titleProp['type'])->toBe('string');
        expect($titleProp)->not()->toHaveKey('format');
    }

    public function test_has_file_upload_detection(): void
    {
        $mapper = new ValidationRuleMapper;

        expect($mapper->hasFileUpload([
            'name' => 'required|string',
            'avatar' => 'required|image|max:2048',
        ]))->toBeTrue();

        expect($mapper->hasFileUpload([
            'name' => 'required|string',
            'email' => 'required|email',
        ]))->toBeFalse();

        expect($mapper->hasFileUpload([
            'document' => ['required', 'mimes:pdf,docx'],
        ]))->toBeTrue();

        expect($mapper->hasFileUpload([
            'document' => 'required|mimetypes:application/pdf',
        ]))->toBeTrue();
    }
}
