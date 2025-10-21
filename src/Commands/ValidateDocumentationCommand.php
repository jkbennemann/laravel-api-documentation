<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\Services\CapturedResponseRepository;
use JkBennemann\LaravelApiDocumentation\Services\DocumentationValidator;
use JkBennemann\LaravelApiDocumentation\Services\RouteComposition;

class ValidateDocumentationCommand extends Command
{
    protected $signature = 'documentation:validate
                            {--strict : Fail if accuracy is below threshold}
                            {--min-accuracy=95 : Minimum accuracy percentage (0-100)}
                            {--route= : Validate specific route only}
                            {--report : Generate detailed validation report}';

    protected $description = 'Validate documentation accuracy against captured responses';

    public function __construct(
        private DocumentationValidator $validator,
        private CapturedResponseRepository $repository,
        private RouteComposition $routeComposition
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔍 Validating API documentation accuracy...');
        $this->newLine();

        // Check if we have any captured responses
        $stats = $this->repository->getStatistics();

        if ($stats['total_routes'] === 0) {
            $this->warn('⚠️  No captured responses found!');
            $this->line('Run: php artisan documentation:capture');
            return self::FAILURE;
        }

        $this->comment("Found {$stats['total_routes']} captured route(s)");
        $this->newLine();

        // Perform validation
        $results = $this->performValidation();

        // Display results
        $this->displayResults($results);

        // Check if we meet accuracy threshold
        $minAccuracy = (float) $this->option('min-accuracy');
        $overallAccuracy = $results['overall_accuracy'];

        if ($this->option('strict') && $overallAccuracy < $minAccuracy) {
            $this->newLine();
            $this->error("❌ Accuracy ({$overallAccuracy}%) is below threshold ({$minAccuracy}%)");
            $this->warn('💡 Captured responses may be outdated. Run: php artisan documentation:capture');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✅ Documentation validation passed');

        // Generate report if requested
        if ($this->option('report')) {
            $this->generateReport($results);
        }

        return self::SUCCESS;
    }

    private function performValidation(): array
    {
        $specificRoute = $this->option('route');

        if ($specificRoute) {
            return $this->validator->validateRoute($specificRoute);
        }

        return $this->validator->validateAll();
    }

    private function displayResults(array $results): void
    {
        // Show overall accuracy
        $accuracy = $results['overall_accuracy'];
        $color = $this->getAccuracyColor($accuracy);

        $this->newLine();
        $this->line("Overall Accuracy: <fg={$color}>{$accuracy}%</>");
        $this->newLine();

        // Show results table
        if (!empty($results['routes'])) {
            $rows = [];

            foreach ($results['routes'] as $route) {
                $accuracyColor = $this->getAccuracyColor($route['accuracy']);
                $status = $route['accuracy'] >= 95 ? '✓' : ($route['accuracy'] >= 80 ? '⚠' : '✗');

                $rows[] = [
                    $status,
                    $route['method'],
                    $route['uri'],
                    "<fg={$accuracyColor}>{$route['accuracy']}%</>",
                    $route['issues'] ?? '-',
                ];
            }

            $this->table(
                ['', 'Method', 'Route', 'Accuracy', 'Issues'],
                $rows
            );
        }

        // Show summary
        $this->displaySummary($results);
    }

    private function getAccuracyColor(float $accuracy): string
    {
        if ($accuracy >= 95) {
            return 'green';
        } elseif ($accuracy >= 80) {
            return 'yellow';
        } else {
            return 'red';
        }
    }

    private function displaySummary(array $results): void
    {
        $this->newLine();
        $this->comment('Summary:');

        $passed = collect($results['routes'])->where('accuracy', '>=', 95)->count();
        $warning = collect($results['routes'])->whereBetween('accuracy', [80, 94.99])->count();
        $failed = collect($results['routes'])->where('accuracy', '<', 80)->count();

        $this->line("  <fg=green>✓ Passed (≥95%):</> {$passed}");
        $this->line("  <fg=yellow>⚠ Warning (80-94%):</> {$warning}");
        $this->line("  <fg=red>✗ Failed (<80%):</> {$failed}");

        // Show common issues if any
        if (!empty($results['common_issues'])) {
            $this->newLine();
            $this->comment('Common Issues:');
            foreach ($results['common_issues'] as $issue => $count) {
                $this->line("  • {$issue}: {$count} occurrence(s)");
            }
        }
    }

    private function generateReport(array $results): void
    {
        $reportPath = storage_path('api-docs/validation-reports');

        if (!is_dir($reportPath)) {
            mkdir($reportPath, 0755, true);
        }

        $filename = $reportPath . '/validation-' . now()->format('Y-m-d_His') . '.json';

        file_put_contents(
            $filename,
            json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->newLine();
        $this->info("📄 Report saved to: {$filename}");
    }
}
