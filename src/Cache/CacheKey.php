<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Cache;

final class CacheKey
{
    public function __construct(
        public readonly string $filePath,
        public readonly int $modificationTime,
    ) {}

    public static function forFile(string $filePath): self
    {
        $mtime = file_exists($filePath) ? filemtime($filePath) : 0;

        return new self($filePath, $mtime ?: 0);
    }

    public function toString(): string
    {
        return md5($this->filePath.':'.$this->modificationTime);
    }
}
