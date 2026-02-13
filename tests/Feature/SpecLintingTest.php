<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use JkBennemann\LaravelApiDocumentation\Lint\SpecLinter;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

class SpecLintingTest extends TestCase
{
    private SpecLinter $linter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->linter = new SpecLinter;
    }

    private function wellDocumentedSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Test API',
                'version' => '1.0.0',
                'description' => 'A well-documented API',
            ],
            'servers' => [
                ['url' => 'https://api.example.com'],
            ],
            'paths' => [
                '/api/users' => [
                    'get' => [
                        'tags' => ['Users'],
                        'summary' => 'List users',
                        'description' => 'Returns a paginated list of users.',
                        'operationId' => 'get.api.users',
                        'responses' => [
                            '200' => [
                                'description' => 'Success',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => ['type' => 'integer', 'example' => 1],
                                                'name' => ['type' => 'string', 'example' => 'John'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Users'],
                        'summary' => 'Create user',
                        'description' => 'Creates a new user account.',
                        'operationId' => 'post.api.users',
                        'security' => [['bearerAuth' => []]],
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => ['type' => 'string', 'example' => 'John'],
                                            'email' => ['type' => 'string', 'example' => 'john@test.com'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Created',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => ['type' => 'integer', 'example' => 1],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Unauthorized'],
                            '422' => ['description' => 'Validation error'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function poorSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1.0.0'],
            'paths' => [
                '/api/users' => [
                    'get' => [
                        'responses' => [
                            '200' => ['description' => 'OK'],
                        ],
                    ],
                    'post' => [
                        'security' => [['bearerAuth' => []]],
                        'responses' => [
                            '201' => ['description' => 'Created'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_well_documented_spec_gets_high_score(): void
    {
        $result = $this->linter->lint($this->wellDocumentedSpec());

        expect($result['score'])->toBeGreaterThanOrEqual(80);
        expect($result['grade'])->toMatch('/^[AB]/');
    }

    public function test_poor_spec_gets_low_score(): void
    {
        $result = $this->linter->lint($this->poorSpec());

        expect($result['score'])->toBeLessThan(80);
    }

    public function test_missing_description_flagged(): void
    {
        $result = $this->linter->lint($this->poorSpec());

        $messages = array_map(fn ($i) => $i->message, $result['issues']);
        expect($messages)->toContain('API description is missing. Add a description to help consumers understand your API.');
    }

    public function test_missing_servers_flagged(): void
    {
        $result = $this->linter->lint($this->poorSpec());

        $messages = array_map(fn ($i) => $i->message, $result['issues']);
        expect($messages)->toContain('No servers defined. Consumers won\'t know the base URL.');
    }

    public function test_missing_summary_flagged(): void
    {
        $result = $this->linter->lint($this->poorSpec());

        $locations = array_map(fn ($i) => $i->location, $result['issues']);
        $messages = array_map(fn ($i) => $i->message, $result['issues']);

        $summaryIssue = false;
        foreach ($result['issues'] as $issue) {
            if (str_contains($issue->message, 'Missing summary')) {
                $summaryIssue = true;

                break;
            }
        }

        expect($summaryIssue)->toBeTrue();
    }

    public function test_missing_error_response_on_authenticated_endpoint(): void
    {
        $result = $this->linter->lint($this->poorSpec());

        $found = false;
        foreach ($result['issues'] as $issue) {
            if (str_contains($issue->message, '401')) {
                $found = true;

                break;
            }
        }

        expect($found)->toBeTrue();
    }

    public function test_missing_request_body_flagged(): void
    {
        $result = $this->linter->lint($this->poorSpec());

        $found = false;
        foreach ($result['issues'] as $issue) {
            if (str_contains($issue->message, 'no request body')) {
                $found = true;

                break;
            }
        }

        expect($found)->toBeTrue();
    }

    public function test_coverage_report_has_percentages(): void
    {
        $result = $this->linter->lint($this->wellDocumentedSpec());

        expect($result['coverage'])->toHaveKey('summaries');
        expect($result['coverage'])->toHaveKey('descriptions');
        expect($result['coverage'])->toHaveKey('examples');
        expect($result['coverage'])->toHaveKey('error_responses');
        expect($result['coverage'])->toHaveKey('request_bodies');
        expect($result['coverage'])->toHaveKey('response_bodies');

        // Well documented spec should have 100% summaries
        expect($result['coverage']['summaries'])->toBe(100);
    }

    public function test_coverage_totals_tracked(): void
    {
        $result = $this->linter->lint($this->wellDocumentedSpec());

        expect($result['coverage']['totals']['operations_total'])->toBe(2);
        expect($result['coverage']['totals']['properties_total'])->toBeGreaterThan(0);
    }

    public function test_grade_matches_score(): void
    {
        $result = $this->linter->lint($this->wellDocumentedSpec());

        // Grade should be consistent with score
        if ($result['score'] >= 90) {
            expect($result['grade'])->toMatch('/^A/');
        } elseif ($result['score'] >= 75) {
            expect($result['grade'])->toMatch('/^B/');
        }
    }

    public function test_mixed_naming_conventions_flagged(): void
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1.0.0', 'description' => 'Test'],
            'servers' => [['url' => 'https://api.example.com']],
            'paths' => [
                '/api/users' => [
                    'post' => [
                        'summary' => 'Create user',
                        'operationId' => 'post.api.users',
                        'requestBody' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'first_name' => ['type' => 'string', 'example' => 'John'],
                                            'last_name' => ['type' => 'string', 'example' => 'Doe'],
                                            'firstName' => ['type' => 'string', 'example' => 'John'],
                                            'lastName' => ['type' => 'string', 'example' => 'Doe'],
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
            ],
        ];

        $result = $this->linter->lint($spec);

        $found = false;
        foreach ($result['issues'] as $issue) {
            if (str_contains($issue->message, 'naming conventions')) {
                $found = true;

                break;
            }
        }

        expect($found)->toBeTrue();
    }

    public function test_empty_spec_handled_gracefully(): void
    {
        $result = $this->linter->lint([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Empty', 'version' => '1.0.0'],
            'paths' => [],
        ]);

        expect($result['score'])->toBeGreaterThanOrEqual(0);
        expect($result['grade'])->toBeString();
    }

    public function test_lint_command_exists(): void
    {
        \Illuminate\Support\Facades\Route::get('api/status', \JkBennemann\LaravelApiDocumentation\Tests\Stubs\StandardApi\Controllers\StatusController::class);

        $this->artisan('api:lint', ['--json' => true])
            ->assertSuccessful();
    }
}
