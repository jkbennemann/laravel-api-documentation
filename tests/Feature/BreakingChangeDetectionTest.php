<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Diff\SpecDiffer;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class BreakingChangeDetectionTest extends TestCase
{
    private SpecDiffer $differ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->differ = new SpecDiffer;
    }

    private function baseSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1.0.0'],
            'paths' => [
                '/api/users' => [
                    'get' => [
                        'summary' => 'List users',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Success',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => ['type' => 'integer'],
                                                'name' => ['type' => 'string'],
                                                'email' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'post' => [
                        'summary' => 'Create user',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => ['type' => 'string'],
                                            'email' => ['type' => 'string'],
                                        ],
                                        'required' => ['name', 'email'],
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
                        'summary' => 'Get user',
                        'responses' => [
                            '200' => ['description' => 'Success'],
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

    public function test_identical_specs_no_changes(): void
    {
        $spec = $this->baseSpec();
        $result = $this->differ->diff($spec, $spec);

        expect($result['breaking'])->toBeEmpty();
        expect($result['non_breaking'])->toBeEmpty();
    }

    public function test_removed_endpoint_is_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        unset($new['paths']['/api/users/{id}']);

        $result = $this->differ->diff($old, $new);

        expect($result['breaking'])->not()->toBeEmpty();
        $messages = array_map(fn ($e) => $e->message, $result['breaking']);
        expect($messages)->toContain('Endpoint removed.');
    }

    public function test_added_endpoint_is_non_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        $new['paths']['/api/posts'] = [
            'get' => [
                'summary' => 'List posts',
                'responses' => ['200' => ['description' => 'OK']],
            ],
        ];

        $result = $this->differ->diff($old, $new);

        expect($result['non_breaking'])->not()->toBeEmpty();
        $locations = array_map(fn ($e) => $e->location, $result['non_breaking']);
        expect($locations)->toContain('GET /api/posts');
    }

    public function test_removed_response_field_is_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        unset($new['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema']['properties']['email']);

        $result = $this->differ->diff($old, $new);

        expect($result['breaking'])->not()->toBeEmpty();
        $found = false;
        foreach ($result['breaking'] as $entry) {
            if (str_contains($entry->message, "'email' removed")) {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_added_optional_field_is_non_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        $new['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema']['properties']['avatar'] = ['type' => 'string'];

        $result = $this->differ->diff($old, $new);

        $found = false;
        foreach ($result['non_breaking'] as $entry) {
            if (str_contains($entry->message, "'avatar'")) {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_new_required_request_field_is_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        $new['paths']['/api/users']['post']['requestBody']['content']['application/json']['schema']['properties']['phone'] = ['type' => 'string'];
        $new['paths']['/api/users']['post']['requestBody']['content']['application/json']['schema']['required'][] = 'phone';

        $result = $this->differ->diff($old, $new);

        $found = false;
        foreach ($result['breaking'] as $entry) {
            if (str_contains($entry->message, "'phone'") && str_contains($entry->message, 'Required')) {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_type_change_is_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        $new['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema']['properties']['id'] = ['type' => 'string'];

        $result = $this->differ->diff($old, $new);

        $found = false;
        foreach ($result['breaking'] as $entry) {
            if (str_contains($entry->message, 'type changed') && str_contains($entry->message, "'id'")) {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_added_auth_requirement_is_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        $new['paths']['/api/users']['get']['security'] = [['bearerAuth' => []]];

        $result = $this->differ->diff($old, $new);

        $found = false;
        foreach ($result['breaking'] as $entry) {
            if (str_contains($entry->message, 'Authentication')) {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_new_required_parameter_is_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        $new['paths']['/api/users']['get']['parameters'][] = [
            'name' => 'status',
            'in' => 'query',
            'required' => true,
            'schema' => ['type' => 'string'],
        ];

        $result = $this->differ->diff($old, $new);

        $found = false;
        foreach ($result['breaking'] as $entry) {
            if (str_contains($entry->message, "'status'") && str_contains($entry->message, 'Required')) {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_new_optional_parameter_is_non_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        $new['paths']['/api/users']['get']['parameters'][] = [
            'name' => 'status',
            'in' => 'query',
            'required' => false,
            'schema' => ['type' => 'string'],
        ];

        $result = $this->differ->diff($old, $new);

        $found = false;
        foreach ($result['non_breaking'] as $entry) {
            if (str_contains($entry->message, "'status'") && str_contains($entry->message, 'Optional')) {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_removed_security_scheme_is_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        unset($new['components']['securitySchemes']['bearerAuth']);

        $result = $this->differ->diff($old, $new);

        $found = false;
        foreach ($result['breaking'] as $entry) {
            if (str_contains($entry->message, 'Security scheme removed')) {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_deprecated_endpoint_is_non_breaking(): void
    {
        $old = $this->baseSpec();
        $new = $this->baseSpec();
        $new['paths']['/api/users']['get']['deprecated'] = true;

        $result = $this->differ->diff($old, $new);

        $found = false;
        foreach ($result['non_breaking'] as $entry) {
            if ($entry->type === 'deprecated') {
                $found = true;

                break;
            }
        }
        expect($found)->toBeTrue();
    }

    public function test_diff_command_with_files(): void
    {
        $tmpDir = sys_get_temp_dir();
        $oldPath = $tmpDir.'/old-spec-'.uniqid().'.json';
        $newPath = $tmpDir.'/new-spec-'.uniqid().'.json';

        try {
            file_put_contents($oldPath, json_encode($this->baseSpec()));

            $newSpec = $this->baseSpec();
            $newSpec['paths']['/api/posts'] = [
                'get' => ['summary' => 'List posts', 'responses' => ['200' => ['description' => 'OK']]],
            ];
            file_put_contents($newPath, json_encode($newSpec));

            $this->artisan('api:diff', [
                'old' => $oldPath,
                'new' => $newPath,
                '--json' => true,
            ])->assertSuccessful();
        } finally {
            @unlink($oldPath);
            @unlink($newPath);
        }
    }

    public function test_diff_command_fail_on_breaking(): void
    {
        $tmpDir = sys_get_temp_dir();
        $oldPath = $tmpDir.'/old-spec-'.uniqid().'.json';
        $newPath = $tmpDir.'/new-spec-'.uniqid().'.json';

        try {
            file_put_contents($oldPath, json_encode($this->baseSpec()));

            $newSpec = $this->baseSpec();
            unset($newSpec['paths']['/api/users/{id}']);
            file_put_contents($newPath, json_encode($newSpec));

            $this->artisan('api:diff', [
                'old' => $oldPath,
                'new' => $newPath,
                '--fail-on-breaking' => true,
                '--json' => true,
            ])->assertFailed();
        } finally {
            @unlink($oldPath);
            @unlink($newPath);
        }
    }
}
