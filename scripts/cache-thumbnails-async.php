<?php
/**
 * Asynchronous thumbnail cacher
 * Runs in background to cache thumbnails for faster API responses
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../cache_manager.php';

use ImageMosaic\CacheManager;

try {
    $data = $argv[1] ?? '{}';
    $urlsToCache = json_decode($data, true);
    
    if (!is_array($urlsToCache) || empty($urlsToCache)) {
        exit(0);
    }
    
    $cache = new CacheManager('public/cache');
    $cached = 0;
    
    // Cache up to 20 thumbnails in background
    foreach (array_slice($urlsToCache, 0, 20) as $hash => $url) {
        try {
            $cache->cacheThumbnail($url, $hash);
            $cached++;
        } catch (\Throwable $e) {
            error_log('Async thumbnail cache error for ' . $hash . ': ' . $e->getMessage());
        }
    }
    
    if ($cached > 0) {
        error_log('Cached ' . $cached . ' thumbnails in background');
    }
    
    exit(0);

} catch (\Throwable $e) {
    error_log('Async cache error: ' . $e->getMessage());
    exit(1);
}
