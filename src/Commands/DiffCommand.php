<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\Diff\SpecDiffer;

class DiffCommand extends Command
{
    protected $signature = 'api:diff
        {old : Path to the old/previous OpenAPI JSON spec}
        {new : Path to the new/current OpenAPI JSON spec}
        {--fail-on-breaking : Exit with non-zero code if breaking changes found}
        {--json : Output results as JSON}';

    protected $description = 'Compare two OpenAPI specs and report breaking vs non-breaking changes';

    public function handle(): int
    {
        $oldPath = $this->argument('old');
        $newPath = $this->argument('new');

        $old = $this->loadSpec($oldPath);
        $new = $this->loadSpec($newPath);

        if ($old === null || $new === null) {
            return self::FAILURE;
        }

        $differ = new SpecDiffer;
        $result = $differ->diff($old, $new);

        if ($this->option('json')) {
            $output = [
                'breaking' => array_map(fn ($e) => [
                    'type' => $e->type,
                    'location' => $e->location,
                    'message' => $e->message,
                ], $result['breaking']),
                'non_breaking' => array_map(fn ($e) => [
                    'type' => $e->type,
                    'location' => $e->location,
                    'message' => $e->message,
                ], $result['non_breaking']),
                'summary' => [
                    'breaking_count' => count($result['breaking']),
                    'non_breaking_count' => count($result['non_breaking']),
                ],
            ];
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->resolveExitCode($result);
        }

        $this->renderResults($result);

        return $this->resolveExitCode($result);
    }

    private function loadSpec(string $path): ?array
    {
        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return null;
        }

        $content = file_get_contents($path);
        $spec = json_decode($content, true);

        if ($spec === null) {
            $this->error("Failed to parse: {$path}");

            return null;
        }

        return $spec;
    }

    private function renderResults(array $result): void
    {
        $breaking = $result['breaking'];
        $nonBreaking = $result['non_breaking'];

        if (empty($breaking) && empty($nonBreaking)) {
            $this->info('No changes detected.');

            return;
        }

        if (! empty($breaking)) {
            $this->error('Breaking Changes ('.count($breaking).')');
            foreach ($breaking as $entry) {
                $icon = match ($entry->type) {
                    'removed' => '-',
                    'added' => '+',
                    'changed' => '~',
                    default => '!',
                };
                $this->line("  <fg=red>{$icon}</> [{$entry->location}] {$entry->message}");
            }
            $this->newLine();
        }

        if (! empty($nonBreaking)) {
            $this->info('Non-Breaking Changes ('.count($nonBreaking).')');
            foreach ($nonBreaking as $entry) {
                $icon = match ($entry->type) {
                    'added' => '+',
                    'changed' => '~',
                    'deprecated' => '!',
                    default => ' ',
                };
                $this->line("  <fg=green>{$icon}</> [{$entry->location}] {$entry->message}");
            }
            $this->newLine();
        }

        $this->line(sprintf(
            'Summary: <fg=%s>%d breaking</>, %d non-breaking',
            count($breaking) > 0 ? 'red' : 'green',
            count($breaking),
            count($nonBreaking),
        ));
    }

    private function resolveExitCode(array $result): int
    {
        if ($this->option('fail-on-breaking') && ! empty($result['breaking'])) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
