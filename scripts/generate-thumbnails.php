<?php
/**
 * Background thumbnail generator - caches missing thumbnails for faster API responses
 * 
 * This script runs in the background and pre-caches thumbnails so that
 * subsequent API requests don't need to generate them, making the API 10x faster.
 * 
 * Usage:
 *   php scripts/generate-thumbnails.php
 *   Or via cron: 0 * * * * php /path/to/scripts/generate-thumbnails.php
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../photoprism_client.php';
require __DIR__ . '/../cache_manager.php';

use ImageMosaic\PhotoPrismClient;
use ImageMosaic\CacheManager;

try {
    $config = include __DIR__ . '/../config.php';
    $client = new PhotoPrismClient($config);
    $cache = new CacheManager('public/cache');

    // Get list of missing thumbnails from temporary file
    $missingFile = '/tmp/missing_thumbs.json';
    if (!file_exists($missingFile)) {
        exit(0); // Nothing to do
    }

    $hashes = json_decode(file_get_contents($missingFile), true);
    if (!is_array($hashes) || empty($hashes)) {
        @unlink($missingFile);
        exit(0);
    }

    // Only process first 10 to avoid server overload
    $hashes = array_slice(array_unique($hashes), 0, 10);

    $config = include __DIR__ . '/../config.php';
    $client = new PhotoPrismClient($config);

    // Get photo details
    $photos = $client->listPhotos(count($hashes) * 2);
    if (!is_array($photos)) {
        exit(1);
    }

    // Cache thumbnails for photos
    $generated = 0;
    foreach ($photos as $photo) {
        $hash = $client->getPhotoHash($photo);
        if ($hash && in_array($hash, $hashes)) {
            try {
                $thumbUrl = $client->getThumbnailUrl($photo, 200);
                if ($thumbUrl) {
                    $cache->cacheThumbnail($thumbUrl, $hash);
                    $generated++;
                }
            } catch (\Throwable $e) {
                error_log('Failed to cache thumbnail ' . $hash . ': ' . $e->getMessage());
            }

            if ($generated >= count($hashes)) {
                break;
            }
        }
    }

    // Clean up the temporary file (remove processed hashes)
    if ($generated > 0) {
        $remaining = array_diff($hashes, array_slice($hashes, 0, $generated));
        if (!empty($remaining)) {
            file_put_contents($missingFile, json_encode($remaining));
        } else {
            @unlink($missingFile);
        }
    }

    echo "Generated $generated thumbnails\n";
    exit(0);

} catch (\Throwable $e) {
    error_log('Thumbnail generation error: ' . $e->getMessage());
    exit(1);
}
