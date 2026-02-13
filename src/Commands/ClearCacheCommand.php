<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Commands;

use Illuminate\Console\Command;
use JkBennemann\LaravelApiDocumentation\Cache\AstCache;
use JkBennemann\LaravelApiDocumentation\Repository\CapturedResponseRepository;

class ClearCacheCommand extends Command
{
    protected $signature = 'api:clear-cache
        {--ast : Clear AST analysis cache only}
        {--captures : Clear captured responses only}';

    protected $description = 'Clear API documentation caches';

    public function handle(AstCache $astCache, CapturedResponseRepository $capturedRepo): int
    {
        $clearAst = $this->option('ast');
        $clearCaptures = $this->option('captures');

        // If no specific option, clear both
        if (! $clearAst && ! $clearCaptures) {
            $clearAst = true;
            $clearCaptures = true;
        }

        if ($clearAst) {
            $astCache->clear();
            $this->info('AST analysis cache cleared.');
        }

        if ($clearCaptures) {
            $count = $capturedRepo->clearAll();
            $this->info("Captured responses cleared ({$count} files removed).");
        }

        return self::SUCCESS;
    }
}
