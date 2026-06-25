<?php
/**
 * Asynchronous caching and base64 encoding
 * Runs in background to cache thumbnails and generate base64 after initial API response
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../cache_manager.php';

use ImageMosaic\CacheManager;

try {
    $jobsFile = '/tmp/cache_jobs.json';
    if (!file_exists($jobsFile)) {
        exit(0);
    }

    $jobs = [];
    $handle = @fopen($jobsFile, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $data = json_decode(trim($line), true);
            if (is_array($data) && !empty($data['urls'])) {
                $jobs = array_merge($jobs, $data['urls']);
            }
        }
        fclose($handle);
        @unlink($jobsFile); // Clear job queue
    }

    if (empty($jobs)) {
        exit(0);
    }

    $cache = new CacheManager('public/cache');
    $cached = 0;

    // Cache up to 50 thumbnails asynchronously
    foreach (array_slice($jobs, 0, 50) as $hash => $url) {
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
