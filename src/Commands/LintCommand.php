<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\Discovery\RouteDiscovery;
use JkBennemann\LaravelApiDocumentation\Emission\OpenApiEmitter;
use JkBennemann\LaravelApiDocumentation\Lint\SpecLinter;
use JkBennemann\LaravelApiDocumentation\Schema\SchemaRegistry;

class LintCommand extends Command
{
    protected $signature = 'api:lint
        {--file= : Path to an existing OpenAPI JSON file to lint (instead of generating)}
        {--domain= : Generate and lint a specific domain}
        {--json : Output results as JSON}';

    protected $description = 'Lint the OpenAPI spec for quality issues and show coverage report';

    public function handle(
        RouteDiscovery $discovery,
        OpenApiEmitter $emitter,
        SchemaRegistry $registry,
    ): int {
        $spec = $this->resolveSpec($discovery, $emitter, $registry);

        if ($spec === null) {
            return self::FAILURE;
        }

        $linter = new SpecLinter;
        $result = $linter->lint($spec);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($result);
        }

        $this->renderCoverageTable($result['coverage']);
        $this->newLine();
        $this->renderIssues($result['issues']);
        $this->newLine();
        $this->renderScore($result['score'], $result['grade']);

        return $this->exitCode($result);
    }

    private function resolveSpec(RouteDiscovery $discovery, OpenApiEmitter $emitter, SchemaRegistry $registry): ?array
    {
        if ($file = $this->option('file')) {
            if (! file_exists($file)) {
                $this->error("File not found: {$file}");

                return null;
            }

            $content = file_get_contents($file);
            $spec = json_decode($content, true);

            if ($spec === null) {
                $this->error('Failed to parse JSON file.');

                return null;
            }

            return $spec;
        }

        // Generate spec
        $this->info('Generating spec for linting...');
        $registry->reset();

        $contexts = $discovery->discover($this->option('domain'));

        if (empty($contexts)) {
            $this->error('No routes found.');

            return null;
        }

        return $emitter->emit($contexts, array_merge(
            config('api-documentation', []),
            [
                'open_api_version' => config('api-documentation.open_api_version', '3.1.0'),
                'version' => config('api-documentation.version', '1.0.0'),
                'title' => config('api-documentation.title', 'API Documentation'),
            ],
        ));
    }

    private function renderCoverageTable(array $coverage): void
    {
        $this->info('Coverage Report');
        $this->table(
            ['Metric', 'Coverage', 'Count'],
            [
                ['Endpoints with summaries', $this->formatPct($coverage['summaries']), "{$coverage['totals']['operations_with_summary']}/{$coverage['totals']['operations_total']}"],
                ['Endpoints with descriptions', $this->formatPct($coverage['descriptions']), "{$coverage['totals']['operations_with_description']}/{$coverage['totals']['operations_total']}"],
                ['Properties with examples', $this->formatPct($coverage['examples']), "{$coverage['totals']['properties_with_examples']}/{$coverage['totals']['properties_total']}"],
                ['Error responses documented', $this->formatPct($coverage['error_responses']), "{$coverage['totals']['operations_with_error_responses']}/{$coverage['totals']['operations_needing_error_responses']}"],
                ['Request bodies documented', $this->formatPct($coverage['request_bodies']), "{$coverage['totals']['request_bodies_documented']}/{$coverage['totals']['request_bodies_total']}"],
                ['Response bodies documented', $this->formatPct($coverage['response_bodies']), "{$coverage['totals']['response_bodies_documented']}/{$coverage['totals']['response_bodies_total']}"],
            ]
        );
    }

    private function renderIssues(array $issues): void
    {
        if (empty($issues)) {
            $this->info('No issues found!');

            return;
        }

        $errors = array_filter($issues, fn ($i) => $i->severity === 'error');
        $warnings = array_filter($issues, fn ($i) => $i->severity === 'warning');
        $infos = array_filter($issues, fn ($i) => $i->severity === 'info');

        $this->info('Issues ('.count($issues).' total)');

        foreach ($errors as $issue) {
            $this->error("  [{$issue->location}] {$issue->message}");
        }

        foreach ($warnings as $issue) {
            $this->warn("  [{$issue->location}] {$issue->message}");
        }

        foreach ($infos as $issue) {
            $this->line("  <fg=cyan>[{$issue->location}]</> {$issue->message}");
        }
    }

    private function renderScore(int $score, string $grade): void
    {
        $color = match (true) {
            $score >= 85 => 'green',
            $score >= 70 => 'yellow',
            default => 'red',
        };

        $this->line("<fg={$color};options=bold>Quality Score: {$score}/100 ({$grade})</>");
    }

    private function formatPct(int $pct): string
    {
        if ($pct >= 90) {
            return "<fg=green>{$pct}%</>";
        }
        if ($pct >= 70) {
            return "<fg=yellow>{$pct}%</>";
        }

        return "<fg=red>{$pct}%</>";
    }

    private function exitCode(array $result): int
    {
        $errors = count(array_filter($result['issues'], fn ($i) => $i->severity === 'error'));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
