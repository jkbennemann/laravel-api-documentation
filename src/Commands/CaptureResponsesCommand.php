<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\Services\CapturedResponseRepository;

class CaptureResponsesCommand extends Command
{
    protected $signature = 'documentation:capture
                            {--clear : Clear existing captures before running}
                            {--stats : Show capture statistics after completion}';

    protected $description = 'Capture API responses by running tests with capture middleware enabled';

    public function __construct(
        private CapturedResponseRepository $repository
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🎬 Starting API response capture...');
        $this->newLine();

        // Safety check
        if (app()->environment('production')) {
            $this->error('❌ Cannot run capture in production environment!');
            return self::FAILURE;
        }

        // Clear existing captures if requested
        if ($this->option('clear')) {
            $this->clearExistingCaptures();
        }

        // Show current configuration
        $this->displayConfiguration();

        // Enable capture mode temporarily
        $originalValue = config('api-documentation.capture.enabled');
        config(['api-documentation.capture.enabled' => true]);

        $this->info('Running test suite with capture enabled...');
        $this->newLine();

        // Run tests
        $exitCode = $this->runTests();

        // Restore original configuration
        config(['api-documentation.capture.enabled' => $originalValue]);

        if ($exitCode === 0) {
            $this->newLine();
            $this->info('✅ Capture completed successfully!');
            $this->newLine();

            // Show statistics
            $this->showCaptureStatistics();

            // Show next steps
            $this->showNextSteps();

            return self::SUCCESS;
        } else {
            $this->newLine();
            $this->error('❌ Tests failed during capture');
            $this->warn('💡 Fix failing tests and try again');

            return self::FAILURE;
        }
    }

    private function clearExistingCaptures(): void
    {
        $count = $this->repository->clearAll();

        if ($count > 0) {
            $this->warn("🗑️  Cleared {$count} existing capture(s)");
            $this->newLine();
        }
    }

    private function displayConfiguration(): void
    {
        $storagePath = $this->repository->getStoragePath();

        $this->comment('Configuration:');
        $this->line("  Storage: {$storagePath}");
        $this->line('  Sanitization: ' . (config('api-documentation.capture.sanitize.enabled') ? 'Enabled' : 'Disabled'));
        $this->line('  Max size: ' . number_format(config('api-documentation.capture.rules.max_size', 102400) / 1024) . 'KB');
        $this->newLine();
    }

    private function runTests(): int
    {
        // Detect test framework
        if (file_exists(base_path('vendor/bin/pest'))) {
            return $this->runPestTests();
        } elseif (file_exists(base_path('vendor/bin/phpunit'))) {
            return $this->runPhpUnitTests();
        } else {
            $this->error('No test framework detected (looking for pest or phpunit)');
            return self::FAILURE;
        }
    }

    private function runPestTests(): int
    {
        $command = base_path('vendor/bin/pest');

        // Add any test filters here if needed
        passthru($command, $exitCode);

        return $exitCode;
    }

    private function runPhpUnitTests(): int
    {
        $command = base_path('vendor/bin/phpunit');

        passthru($command, $exitCode);

        return $exitCode;
    }

    private function showCaptureStatistics(): void
    {
        $stats = $this->repository->getStatistics();

        $this->comment('📊 Capture Statistics:');
        $this->line("  Total routes captured: {$stats['total_routes']}");
        $this->line("  Total responses: {$stats['total_responses']}");

        if (!empty($stats['by_method'])) {
            $this->newLine();
            $this->comment('  By HTTP Method:');
            foreach ($stats['by_method'] as $method => $count) {
                $this->line("    {$method}: {$count}");
            }
        }

        if (!empty($stats['by_status'])) {
            $this->newLine();
            $this->comment('  By Status Code:');
            foreach ($stats['by_status'] as $status => $count) {
                $this->line("    {$status}: {$count}");
            }
        }

        $this->newLine();

        // Show detailed list if requested
        if ($this->option('stats')) {
            $this->showDetailedList();
        }
    }

    private function showDetailedList(): void
    {
        $all = $this->repository->getAll();

        if ($all->isEmpty()) {
            return;
        }

        $this->comment('📝 Captured Responses:');
        $this->newLine();

        $rows = $all->map(function ($item) {
            $statuses = implode(', ', array_keys($item['responses']));
            $timestamps = array_column($item['responses'], 'captured_at');
            $lastCapture = !empty($timestamps) ? max($timestamps) : 'Unknown';

            return [
                $item['method'],
                $item['route'],
                $statuses,
                \Carbon\Carbon::parse($lastCapture)->diffForHumans(),
            ];
        })->toArray();

        $this->table(
            ['Method', 'Route', 'Status Codes', 'Last Captured'],
            $rows
        );
    }

    private function showNextSteps(): void
    {
        $this->comment('Next steps:');
        $this->line('  1. Review captured responses in: ' . $this->repository->getStoragePath());
        $this->line('  2. Generate documentation: php artisan documentation:generate');
        $this->line('  3. Validate accuracy: php artisan documentation:validate');
        $this->line('  4. Commit .schemas/ directory to version control');
    }
}
