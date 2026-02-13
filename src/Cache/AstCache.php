<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Cache;

use PhpParser\Parser;
use PhpParser\ParserFactory;

class AstCache
{
    private string $cachePath;

    private int $ttl;

    private Parser $parser;

    /** @var array<string, mixed> In-memory cache for current request */
    private array $memoryCache = [];

    /** @var array<string, \PhpParser\Node\Stmt[]|null> In-memory parsed statement cache */
    private array $parsedStatements = [];

    public function __construct(?string $cachePath = null, int $ttl = 3600)
    {
        $this->cachePath = $cachePath ?? storage_path('framework/cache/api-docs');
        $this->ttl = $ttl;
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Parse a PHP file and return the AST statements, using an in-memory cache
     * to avoid re-parsing the same file within a single generation run.
     *
     * @return \PhpParser\Node\Stmt[]|null
     */
    public function parseFile(string $filePath): ?array
    {
        if (isset($this->parsedStatements[$filePath])) {
            return $this->parsedStatements[$filePath];
        }

        $code = @file_get_contents($filePath);
        if ($code === false) {
            return null;
        }

        try {
            $stmts = $this->parser->parse($code);
        } catch (\Throwable) {
            return null;
        }

        if ($stmts !== null) {
            $this->parsedStatements[$filePath] = $stmts;
        }

        return $stmts;
    }

    /**
     * Get cached data for a file, or null if stale/missing.
     */
    public function get(string $filePath): mixed
    {
        if ($this->ttl === 0) {
            return null;
        }

        $key = CacheKey::forFile($filePath);
        $cacheId = $key->toString();

        // Check memory cache first
        if (isset($this->memoryCache[$cacheId])) {
            return $this->memoryCache[$cacheId];
        }

        $cacheFile = $this->getCacheFilePath($cacheId);
        if (! file_exists($cacheFile)) {
            return null;
        }

        // Check TTL
        $cacheAge = time() - filemtime($cacheFile);
        if ($cacheAge > $this->ttl) {
            @unlink($cacheFile);

            return null;
        }

        try {
            $data = unserialize(file_get_contents($cacheFile), ['allowed_classes' => false]);
            $this->memoryCache[$cacheId] = $data;

            return $data;
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: AST cache read failed for {$filePath}, removing corrupt entry: {$e->getMessage()}");
            }
            @unlink($cacheFile);

            return null;
        }
    }

    public function put(string $filePath, mixed $data): void
    {
        if ($this->ttl === 0) {
            return;
        }

        $key = CacheKey::forFile($filePath);
        $cacheId = $key->toString();

        $this->memoryCache[$cacheId] = $data;

        $cacheFile = $this->getCacheFilePath($cacheId);
        $dir = dirname($cacheFile);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            file_put_contents($cacheFile, serialize($data));
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->debug("API Docs: AST cache write failed: {$e->getMessage()}");
            }
        }
    }

    public function clear(): void
    {
        $this->memoryCache = [];
        $this->parsedStatements = [];

        if (is_dir($this->cachePath)) {
            $files = glob($this->cachePath.'/*.cache');
            foreach ($files ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    private function getCacheFilePath(string $cacheId): string
    {
        return $this->cachePath.'/'.$cacheId.'.cache';
    }
}
