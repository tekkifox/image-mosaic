<?php

declare(strict_types=1);

namespace ImageMosaic;

use RuntimeException;
use InvalidArgumentException;

class CacheManager
{
    private const CACHE_VERSION = '1';
    private const DEFAULT_TTL = 30 * 24 * 60 * 60; // 30 days
    private const TEMP_SUFFIX = '.tmp';

    private string $cacheDir;
    private int $defaultTtl;
    private string $lockDir;

    public function __construct(string $cacheDir = 'public/cache', int $defaultTtl = self::DEFAULT_TTL)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->defaultTtl = $defaultTtl;
        $this->lockDir = $this->cacheDir . '/.locks';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }
    }

    /**
     * Get cached content or fetch from URL if not cached.
     *
     * @param string $url URL to fetch
     * @param int|null $ttl Time to live in seconds (null = use default)
     * @return string Content
     * @throws RuntimeException
     */
    public function get(string $url, ?int $ttl = null): string
    {
        $cacheKey = $this->getCacheKey($url);
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        // Check if valid cache exists
        if (file_exists($cachePath) && $this->isCacheValid($cachePath, $ttl ?? $this->defaultTtl)) {
            return file_get_contents($cachePath);
        }

        // Acquire lock to prevent multiple simultaneous downloads
        $lockPath = $this->lockDir . '/' . $cacheKey . '.lock';
        $lockHandle = fopen($lockPath, 'c');
        if (!$lockHandle) {
            throw new RuntimeException("Cannot acquire lock for cache key: $cacheKey");
        }

        if (flock($lockHandle, LOCK_EX)) {
            // Double-check cache after lock acquired
            if (file_exists($cachePath) && $this->isCacheValid($cachePath, $ttl ?? $this->defaultTtl)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                return file_get_contents($cachePath);
            }

            try {
                // Fetch from URL
                $content = $this->fetchUrl($url);

                // Write to temp file first
                $tempPath = $cachePath . self::TEMP_SUFFIX;
                file_put_contents($tempPath, $content);

                // Atomic rename
                rename($tempPath, $cachePath);

                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);

                return $content;
            } catch (RuntimeException $e) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                throw $e;
            }
        }

        fclose($lockHandle);
        throw new RuntimeException("Cannot acquire exclusive lock for: $cacheKey");
    }

    /**
     * Get path to cached file without fetching.
     *
     * @param string $url URL to get cache path for
     * @return string|null Cache file path or null if not cached
     */
    public function getCachedPath(string $url): ?string
    {
        $cacheKey = $this->getCacheKey($url);
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        if (file_exists($cachePath) && is_readable($cachePath)) {
            return $cachePath;
        }

        return null;
    }

    /**
     * Store content in cache.
     *
     * @param string $url URL key
     * @param string $content Content to cache
     * @return string Path to cached file
     * @throws RuntimeException
     */
    public function set(string $url, string $content): string
    {
        $cacheKey = $this->getCacheKey($url);
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        $tempPath = $cachePath . self::TEMP_SUFFIX;
        if (!file_put_contents($tempPath, $content)) {
            throw new RuntimeException("Cannot write to cache: $cachePath");
        }

        if (!rename($tempPath, $cachePath)) {
            @unlink($tempPath);
            throw new RuntimeException("Cannot finalize cache: $cachePath");
        }

        return $cachePath;
    }

    /**
     * Get cache metadata.
     *
     * @param string $url URL key
     * @return array|null Cache metadata or null if not cached
     */
    public function getMetadata(string $url): ?array
    {
        $cacheKey = $this->getCacheKey($url);
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        if (!file_exists($cachePath)) {
            return null;
        }

        return [
            'path' => $cachePath,
            'size' => filesize($cachePath),
            'mtime' => filemtime($cachePath),
            'created' => date('Y-m-d H:i:s', filemtime($cachePath)),
            'age_seconds' => time() - filemtime($cachePath),
            'url_hash' => $cacheKey,
        ];
    }

    /**
     * Delete cached file.
     *
     * @param string $url URL key
     * @return bool True if deleted, false if not found
     */
    public function delete(string $url): bool
    {
        $cacheKey = $this->getCacheKey($url);
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        if (file_exists($cachePath)) {
            return unlink($cachePath);
        }

        return false;
    }

    /**
     * Check if URL is cached and valid.
     *
     * @param string $url URL to check
     * @param int|null $ttl Time to live in seconds
     * @return bool True if cached and valid
     */
    public function has(string $url, ?int $ttl = null): bool
    {
        $cacheKey = $this->getCacheKey($url);
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        if (!file_exists($cachePath)) {
            return false;
        }

        return $this->isCacheValid($cachePath, $ttl ?? $this->defaultTtl);
    }

    /**
     * Get cache statistics.
     *
     * @return array Cache stats
     */
    public function getStats(): array
    {
        $files = array_diff(scandir($this->cacheDir) ?: [], ['.', '..', '.locks']);
        $totalSize = 0;
        $fileCount = 0;
        $oldestFile = null;
        $newestFile = null;
        $oldestTime = PHP_INT_MAX;
        $newestTime = 0;

        foreach ($files as $file) {
            if ($file === '.locks' || is_dir($this->cacheDir . '/' . $file)) {
                continue;
            }

            $path = $this->cacheDir . '/' . $file;
            $size = filesize($path);
            $mtime = filemtime($path);

            $totalSize += $size;
            $fileCount++;

            if ($mtime < $oldestTime) {
                $oldestTime = $mtime;
                $oldestFile = $file;
            }

            if ($mtime > $newestTime) {
                $newestTime = $mtime;
                $newestFile = $file;
            }
        }

        return [
            'cache_dir' => $this->cacheDir,
            'file_count' => $fileCount,
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'oldest_file' => $oldestFile,
            'oldest_time' => $oldestFile ? $oldestTime : null,
            'newest_file' => $newestFile,
            'newest_time' => $newestFile ? $newestTime : null,
            'default_ttl_days' => $this->defaultTtl / (24 * 60 * 60),
        ];
    }

    /**
     * Clean expired cache files.
     *
     * @param int|null $ttl Only remove files older than this
     * @return int Number of files removed
     */
    public function cleanup(?int $ttl = null): int
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $removed = 0;
        $files = array_diff(scandir($this->cacheDir) ?: [], ['.', '..', '.locks']);

        foreach ($files as $file) {
            if ($file === '.locks' || is_dir($this->cacheDir . '/' . $file)) {
                continue;
            }

            $path = $this->cacheDir . '/' . $file;
            $mtime = filemtime($path);

            if (time() - $mtime > $ttl) {
                if (unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Pre-cache multiple images in parallel (for prefetching).
     *
     * @param array $urls URLs to cache
     * @return array Results array with URL => status
     */
    public function cacheMultiple(array $urls): array
    {
        $results = [];
        
        foreach ($urls as $url) {
            if (!is_string($url) || empty($url)) {
                continue;
            }
            
            // Check if already cached (skip if recent)
            if ($this->has($url, 24 * 60 * 60)) { // Skip if cached in last 24 hours
                $results[$url] = 'already_cached';
                continue;
            }
            
            try {
                // Try to fetch with short timeout (prefetch is low priority)
                $context = stream_context_create([
                    'http' => ['timeout' => 10],
                    'ssl' => ['verify_peer' => true],
                ]);
                
                $content = @file_get_contents($url, false, $context);
                
                if ($content === false || empty($content)) {
                    $results[$url] = 'failed';
                    continue;
                }
                
                $this->set($url, $content);
                $results[$url] = 'cached';
            } catch (RuntimeException $e) {
                $results[$url] = 'error: ' . $e->getMessage();
            }
        }
        
        return $results;
    }

    /**
     * Clear all cache files.
     *
     * @return int Number of files removed
     */
    public function flush(): int
    {
        $removed = 0;
        $files = array_diff(scandir($this->cacheDir) ?: [], ['.', '..', '.locks']);

        foreach ($files as $file) {
            if ($file === '.locks' || is_dir($this->cacheDir . '/' . $file)) {
                continue;
            }

            if (unlink($this->cacheDir . '/' . $file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Generate cache key from URL.
     *
     * @param string $url URL to hash
     * @return string Cache key filename
     */
    private function getCacheKey(string $url): string
    {
        // Extract file extension from URL
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $ext = $ext ? '.' . $ext : '';

        // Create hash-based filename
        return 'v' . self::CACHE_VERSION . '_' . hash('sha256', $url) . $ext;
    }

    /**
     * Check if cache file is still valid.
     *
     * @param string $path File path
     * @param int $ttl Time to live in seconds
     * @return bool True if cache is valid
     */
    private function isCacheValid(string $path, int $ttl): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $mtime = @filemtime($path);
        if ($mtime === false) {
            return false;
        }

        return (time() - $mtime) < $ttl;
    }

    /**
     * Fetch URL content with error handling.
     *
     * @param string $url URL to fetch
     * @return string Content
     * @throws RuntimeException
     */
    private function fetchUrl(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Image-Mosaic/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $errors = error_get_last();
            $errorMsg = $errors ? $errors['message'] : 'Unknown error';
            throw new RuntimeException("Cannot fetch URL: {$url} - {$errorMsg}");
        }

        if (empty($content)) {
            throw new RuntimeException("Fetched empty content from: {$url}");
        }

        return $content;
    }

    /**
     * Cache and return a thumbnail - optimized for speed
     * 
     * @param string $url PhotoPrism thumbnail URL
     * @param string $hash Photo hash for unique identification
     * @return string File path to cached thumbnail (e.g., /public/cache/thumb_hash_200.jpg)
     */
    public function cacheThumbnail(string $url, string $hash): string
    {
        // Use separate naming for thumbnails to keep them organized
        $thumbDir = $this->cacheDir;
        $thumbFile = 'thumb_' . $hash . '.jpg';
        $thumbPath = $thumbDir . '/' . $thumbFile;

        // If already cached, return path immediately
        if (file_exists($thumbPath)) {
            return '/' . ltrim($thumbDir, '/') . '/' . $thumbFile;
        }

        // Fetch and cache thumbnail
        try {
            $content = $this->fetchUrl($url);
            if (!empty($content)) {
                @mkdir($thumbDir, 0755, true);
                file_put_contents($thumbPath, $content);
                
                return '/' . ltrim($thumbDir, '/') . '/' . $thumbFile;
            }
        } catch (\Throwable $e) {
            // Log error but don't fail - will use fallback in API
            error_log('Thumbnail cache error for ' . $hash . ': ' . $e->getMessage());
        }

        // Return original URL if caching fails (fallback)
        return $url;
    }

    /**
     * Get thumbnail path if cached, null if not cached
     * 
     * @param string $hash Photo hash
     * @return string|null Cached thumbnail path or null
     */
    public function getThumbnailPath(string $hash): ?string
    {
        $thumbFile = 'thumb_' . $hash . '.jpg';
        $thumbPath = $this->cacheDir . '/' . $thumbFile;

        if (file_exists($thumbPath)) {
            return '/' . ltrim($this->cacheDir, '/') . '/' . $thumbFile;
        }

        return null;
    }
}
