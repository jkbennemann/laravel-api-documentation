<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use JkBennemann\LaravelApiDocumentation\Tests\Stubs\Controllers\SimpleController;
use JkBennemann\LaravelApiDocumentation\Tests\TestCase;

/**
 * Warn about a memory limit that cannot finish the job, before starting.
 *
 * Running out of memory here does not produce an error anyone sees: the process exits 255 with
 * nothing on stdout or stderr, shutdown functions are never reached, and the document from the
 * previous run is still on disk looking current. Two commits shipped a stale document that way.
 *
 * Nothing inside the process can report a death like that — a shutdown handler was tried and never
 * fired — so the warning has to come first, while there is still a process to print it.
 */
class MemoryLimitWarningTest extends TestCase
{
    private ?string $originalLimit = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLimit = ini_get('memory_limit') ?: null;
        Route::get('api/thing', [SimpleController::class, 'simple']);
    }

    protected function tearDown(): void
    {
        if ($this->originalLimit !== null) {
            ini_set('memory_limit', $this->originalLimit);
        }
        parent::tearDown();
    }

    public function test_a_tight_limit_is_called_out_before_generation_starts(): void
    {
        ini_set('memory_limit', '128M');
        config(['api-documentation.minimum_memory_mb' => 512]);

        Artisan::call('api:generate');
        $output = Artisan::output();

        $this->assertStringContainsString('memory_limit is 128M', $output);
        $this->assertStringContainsString('memory_limit=512M', $output, 'The warning must name the command that fixes it.');
    }

    public function test_an_adequate_limit_says_nothing(): void
    {
        ini_set('memory_limit', '2048M');
        config(['api-documentation.minimum_memory_mb' => 512]);

        Artisan::call('api:generate');

        $this->assertStringNotContainsString('memory_limit is', Artisan::output());
    }

    public function test_an_unlimited_process_says_nothing(): void
    {
        ini_set('memory_limit', '-1');
        config(['api-documentation.minimum_memory_mb' => 512]);

        Artisan::call('api:generate');

        $this->assertStringNotContainsString('memory_limit is', Artisan::output());
    }
}
