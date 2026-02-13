<?php

declare(strict_types=1);

use JkBennemann\LaravelApiDocumentation\Cache\AstCache;

beforeEach(function () {
    $this->cachePath = sys_get_temp_dir().'/ast-cache-test-'.uniqid();
    @mkdir($this->cachePath, 0755, true);
});

afterEach(function () {
    // Clean up
    if (is_dir($this->cachePath)) {
        $files = glob($this->cachePath.'/*');
        foreach ($files ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cachePath);
    }
});

it('parses a PHP file and returns AST statements', function () {
    $cache = new AstCache($this->cachePath, 0);

    // Use a real PHP file from our own codebase
    $filePath = __DIR__.'/../Stubs/Controllers/SimpleController.php';
    $stmts = $cache->parseFile($filePath);

    expect($stmts)->toBeArray()
        ->and($stmts)->not()->toBeEmpty();
});

it('returns cached statements on second parseFile call', function () {
    $cache = new AstCache($this->cachePath, 0);

    $filePath = __DIR__.'/../Stubs/Controllers/SimpleController.php';

    $first = $cache->parseFile($filePath);
    $second = $cache->parseFile($filePath);

    // Same array reference from in-memory cache
    expect($first)->toBe($second);
});

it('returns null for non-existent file', function () {
    $cache = new AstCache($this->cachePath, 0);

    $result = $cache->parseFile('/non/existent/path.php');

    expect($result)->toBeNull();
});

it('clears parsed statements cache', function () {
    $cache = new AstCache($this->cachePath, 0);

    $filePath = __DIR__.'/../Stubs/Controllers/SimpleController.php';
    $first = $cache->parseFile($filePath);

    $cache->clear();

    // After clear, should re-parse (different array instance)
    $second = $cache->parseFile($filePath);

    expect($first)->toEqual($second)
        ->and($first)->not()->toBe($second);
});

it('stores and retrieves data from disk cache', function () {
    $cache = new AstCache($this->cachePath, 3600);

    $testFile = $this->cachePath.'/test-source.php';
    file_put_contents($testFile, '<?php echo "hello";');

    $cache->put($testFile, ['key' => 'value']);
    $result = $cache->get($testFile);

    expect($result)->toBe(['key' => 'value']);
});

it('returns null from get when TTL is 0', function () {
    $cache = new AstCache($this->cachePath, 0);

    $testFile = $this->cachePath.'/test-source.php';
    file_put_contents($testFile, '<?php echo "hello";');

    $cache->put($testFile, ['key' => 'value']);
    $result = $cache->get($testFile);

    expect($result)->toBeNull();
});

it('returns null for missing cache entry', function () {
    $cache = new AstCache($this->cachePath, 3600);

    $result = $cache->get('/some/uncached/file.php');

    expect($result)->toBeNull();
});

it('clears disk cache files', function () {
    $cache = new AstCache($this->cachePath, 3600);

    $testFile = $this->cachePath.'/test-source.php';
    file_put_contents($testFile, '<?php echo "hello";');

    $cache->put($testFile, 'data');

    // Verify cache file exists
    $cacheFiles = glob($this->cachePath.'/*.cache');
    expect($cacheFiles)->not()->toBeEmpty();

    $cache->clear();

    $cacheFiles = glob($this->cachePath.'/*.cache');
    expect($cacheFiles)->toBeEmpty();
});

it('handles corrupt cache file gracefully', function () {
    $cache = new AstCache($this->cachePath, 3600);

    $testFile = $this->cachePath.'/test-source.php';
    file_put_contents($testFile, '<?php echo "hello";');

    // Write corrupt data directly to cache file location
    $cache->put($testFile, 'valid-data');

    // Corrupt the cache file
    $cacheFiles = glob($this->cachePath.'/*.cache');
    expect($cacheFiles)->not()->toBeEmpty();
    file_put_contents($cacheFiles[0], 'not-valid-serialized-data{{{');

    // Create a fresh instance (no memory cache)
    $freshCache = new AstCache($this->cachePath, 3600);
    $result = $freshCache->get($testFile);

    expect($result)->toBeNull();
});
