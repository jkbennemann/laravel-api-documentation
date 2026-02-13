<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Output\PostmanCollectionWriter;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class PostmanCollectionExportTest extends TestCase
{
    private PostmanCollectionWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new PostmanCollectionWriter;
    }

    private function minimalSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Test API',
                'version' => '1.0.0',
                'description' => 'A test API',
            ],
            'servers' => [
                ['url' => 'https://api.example.com'],
            ],
            'paths' => [
                '/api/users' => [
                    'get' => [
                        'tags' => ['Users'],
                        'summary' => 'List users',
                        'operationId' => 'get.api.users',
                        'responses' => [
                            '200' => ['description' => 'Success'],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Users'],
                        'summary' => 'Create user',
                        'operationId' => 'post.api.users',
                        'requestBody' => [
                            'required' => true,
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
                        'responses' => [
                            '201' => ['description' => 'Created'],
                        ],
                    ],
                ],
                '/api/users/{id}' => [
                    'get' => [
                        'tags' => ['Users'],
                        'summary' => 'Get user',
                        'operationId' => 'get.api.users.id',
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'integer', 'example' => 1],
                            ],
                        ],
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '200' => ['description' => 'Success'],
                        ],
                    ],
                ],
                '/api/status' => [
                    'get' => [
                        'summary' => 'Health check',
                        'operationId' => 'get.api.status',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
        ];
    }

    public function test_collection_has_correct_structure(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        expect($collection)->toHaveKey('info');
        expect($collection)->toHaveKey('item');
        expect($collection)->toHaveKey('variable');
        expect($collection['info']['name'])->toBe('Test API');
        expect($collection['info']['schema'])->toBe('https://schema.getpostman.com/json/collection/v2.1.0/collection.json');
    }

    public function test_base_url_extracted_from_servers(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        $baseUrlVar = collect($collection['variable'])->firstWhere('key', 'baseUrl');
        expect($baseUrlVar['value'])->toBe('https://api.example.com');
    }

    public function test_endpoints_grouped_by_tag(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        // Should have 2 folders: "Users" and "default"
        $folderNames = array_column($collection['item'], 'name');
        expect($folderNames)->toContain('Users');
        expect($folderNames)->toContain('default');
    }

    public function test_request_methods_correct(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        $usersFolder = collect($collection['item'])->firstWhere('name', 'Users');
        $requests = collect($usersFolder['item']);

        $listUsers = $requests->firstWhere('name', 'List users');
        expect($listUsers['request']['method'])->toBe('GET');

        $createUser = $requests->firstWhere('name', 'Create user');
        expect($createUser['request']['method'])->toBe('POST');
    }

    public function test_request_body_included(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        $usersFolder = collect($collection['item'])->firstWhere('name', 'Users');
        $createUser = collect($usersFolder['item'])->firstWhere('name', 'Create user');

        expect($createUser['request'])->toHaveKey('body');
        expect($createUser['request']['body']['mode'])->toBe('raw');
        expect($createUser['request']['body']['raw'])->toContain('John');
        expect($createUser['request']['body']['raw'])->toContain('john@example.com');
    }

    public function test_path_parameters_converted(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        $usersFolder = collect($collection['item'])->firstWhere('name', 'Users');
        $getUser = collect($usersFolder['item'])->firstWhere('name', 'Get user');

        expect($getUser['request']['url']['raw'])->toContain(':id');
        expect($getUser['request']['url'])->toHaveKey('variable');
    }

    public function test_auth_included_for_secured_endpoints(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        $usersFolder = collect($collection['item'])->firstWhere('name', 'Users');
        $getUser = collect($usersFolder['item'])->firstWhere('name', 'Get user');

        expect($getUser['request'])->toHaveKey('auth');
        expect($getUser['request']['auth']['type'])->toBe('bearer');
    }

    public function test_collection_level_auth_from_security_schemes(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        expect($collection)->toHaveKey('auth');
        expect($collection['auth']['type'])->toBe('bearer');
    }

    public function test_accept_header_present(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        $usersFolder = collect($collection['item'])->firstWhere('name', 'Users');
        $listUsers = collect($usersFolder['item'])->firstWhere('name', 'List users');

        $headers = $listUsers['request']['header'];
        $acceptHeader = collect($headers)->firstWhere('key', 'Accept');
        expect($acceptHeader['value'])->toBe('application/json');
    }

    public function test_content_type_header_for_post_requests(): void
    {
        $collection = $this->writer->convert($this->minimalSpec());

        $usersFolder = collect($collection['item'])->firstWhere('name', 'Users');
        $createUser = collect($usersFolder['item'])->firstWhere('name', 'Create user');

        $headers = $createUser['request']['header'];
        $ctHeader = collect($headers)->firstWhere('key', 'Content-Type');
        expect($ctHeader['value'])->toBe('application/json');
    }

    public function test_write_creates_file(): void
    {
        $path = sys_get_temp_dir().'/test-postman-'.uniqid().'.json';

        try {
            $this->writer->write($this->minimalSpec(), $path);

            expect(file_exists($path))->toBeTrue();

            $content = json_decode(file_get_contents($path), true);
            expect($content['info']['name'])->toBe('Test API');
        } finally {
            @unlink($path);
        }
    }

    public function test_query_parameters_included(): void
    {
        $spec = $this->minimalSpec();
        $spec['paths']['/api/users']['get']['parameters'] = [
            [
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'example' => 1],
                'description' => 'Page number',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'example' => 15],
            ],
        ];

        $collection = $this->writer->convert($spec);

        $usersFolder = collect($collection['item'])->firstWhere('name', 'Users');
        $listUsers = collect($usersFolder['item'])->firstWhere('name', 'List users');

        expect($listUsers['request']['url'])->toHaveKey('query');
        expect($listUsers['request']['url']['query'])->toHaveCount(2);
    }
}
