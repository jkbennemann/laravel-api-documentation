<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Output\TypeScriptGenerator;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class TypeScriptGenerationTest extends TestCase
{
    private TypeScriptGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new TypeScriptGenerator;
    }

    public function test_generates_interface_from_object_schema(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'User' => [
                        'type' => 'object',
                        'required' => ['id', 'name'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'email' => ['type' => 'string', 'format' => 'email'],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('export interface User {');
        expect($output)->toContain('id: number;');
        expect($output)->toContain('name: string;');
        expect($output)->toContain('email?: string;');
    }

    public function test_generates_array_type(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'UserList' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/User'],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('export type UserList = User[];');
    }

    public function test_generates_enum_type(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Status' => [
                        'type' => 'string',
                        'enum' => ['active', 'inactive', 'suspended'],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain("export type Status = 'active' | 'inactive' | 'suspended';");
    }

    public function test_handles_nullable_types(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Profile' => [
                        'type' => 'object',
                        'properties' => [
                            'bio' => ['type' => ['string', 'null']],
                            'age' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('bio?: string | null;');
        expect($output)->toContain('age?: number | null;');
    }

    public function test_resolves_ref_in_properties(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Post' => [
                        'type' => 'object',
                        'properties' => [
                            'author' => ['$ref' => '#/components/schemas/User'],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Tag'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('author?: User;');
        expect($output)->toContain('tags?: Tag[];');
    }

    public function test_generates_inline_object_type(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Response' => [
                        'type' => 'object',
                        'properties' => [
                            'meta' => [
                                'type' => 'object',
                                'properties' => [
                                    'page' => ['type' => 'integer'],
                                    'total' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('meta?: { page?: number; total?: number };');
    }

    public function test_generates_request_type_from_paths(): void
    {
        $spec = [
            'paths' => [
                '/api/users' => [
                    'post' => [
                        'operationId' => 'post.api.users',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name', 'email'],
                                        'properties' => [
                                            'name' => ['type' => 'string'],
                                            'email' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('export interface PostApiUsersRequest {');
        expect($output)->toContain('name: string;');
        expect($output)->toContain('email: string;');
    }

    public function test_generates_response_type_from_paths(): void
    {
        $spec = [
            'paths' => [
                '/api/users' => [
                    'get' => [
                        'operationId' => 'get.api.users',
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'array',
                                                    'items' => ['$ref' => '#/components/schemas/User'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('export interface GetApiUsersResponse {');
        expect($output)->toContain('data?: User[];');
    }

    public function test_skips_ref_request_body(): void
    {
        $spec = [
            'paths' => [
                '/api/users' => [
                    'post' => [
                        'operationId' => 'post.api.users',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/CreateUser'],
                                ],
                            ],
                        ],
                        'responses' => [],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        // Should NOT generate a PostApiUsersRequest since it's a $ref
        expect($output)->not()->toContain('PostApiUsersRequest');
    }

    public function test_skips_non_success_responses(): void
    {
        $spec = [
            'paths' => [
                '/api/users' => [
                    'get' => [
                        'operationId' => 'get.api.users',
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '422' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'errors' => ['type' => 'object'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('GetApiUsersResponse');
        // 422 error response should not generate a type
        expect($output)->not()->toContain('errors');
    }

    public function test_generates_jsdoc_comments(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'User' => [
                        'type' => 'object',
                        'properties' => [
                            'email' => [
                                'type' => 'string',
                                'format' => 'email',
                                'description' => 'User email address',
                                'example' => 'user@example.com',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('/** User email address');
        expect($output)->toContain('@format email');
        expect($output)->toContain('@example "user@example.com"');
    }

    public function test_handles_boolean_type(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Settings' => [
                        'type' => 'object',
                        'properties' => [
                            'notifications_enabled' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('notifications_enabled?: boolean;');
    }

    public function test_handles_number_type(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Product' => [
                        'type' => 'object',
                        'properties' => [
                            'price' => ['type' => 'number'],
                            'quantity' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('price?: number;');
        expect($output)->toContain('quantity?: number;');
    }

    public function test_handles_object_without_properties(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Metadata' => [
                        'type' => 'object',
                        'properties' => [
                            'extra' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('extra?: Record<string, any>;');
    }

    public function test_handles_array_without_items(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Container' => [
                        'type' => 'object',
                        'properties' => [
                            'items' => ['type' => 'array'],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('items?: any[];');
    }

    public function test_operation_id_to_type_name(): void
    {
        $spec = [
            'paths' => [
                '/api/user-profiles' => [
                    'get' => [
                        'operationId' => 'get.api.user-profiles',
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('export interface GetApiUserProfilesResponse {');
    }

    public function test_writes_to_file(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'User' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];

        $path = sys_get_temp_dir().'/ts-test-'.uniqid().'/api.d.ts';

        $this->generator->write($spec, $path);

        expect(file_exists($path))->toBeTrue();

        $content = file_get_contents($path);
        expect($content)->toContain('export interface User {');
        expect($content)->toContain('id?: number;');

        // Cleanup
        unlink($path);
        rmdir(dirname($path));
    }

    public function test_inline_enum_in_property(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Order' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pending', 'shipped', 'delivered'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain("status?: 'pending' | 'shipped' | 'delivered';");
    }

    public function test_numeric_enum_values(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Priority' => [
                        'type' => 'integer',
                        'enum' => [1, 2, 3],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('export type Priority = 1 | 2 | 3;');
    }

    public function test_empty_spec_produces_header_only(): void
    {
        $spec = [];

        $output = $this->generator->generate($spec);

        expect($output)->toContain('Auto-generated TypeScript types from OpenAPI spec');
        expect($output)->not()->toContain('export');
    }

    public function test_skips_operations_without_operation_id(): void
    {
        $spec = [
            'paths' => [
                '/api/health' => [
                    'get' => [
                        'responses' => [
                            '200' => [
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'status' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $output = $this->generator->generate($spec);

        expect($output)->not()->toContain('export');
    }
}
