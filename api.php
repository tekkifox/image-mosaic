<?php

require __DIR__ . '/config.php';
require __DIR__ . '/photoprism_client.php';
require __DIR__ . '/cache_manager.php';
require __DIR__ . '/image_url_mapper.php';
require __DIR__ . '/includes/functions.php';

use function ImageMosaic\respondJson;
use ImageMosaic\PhotoPrismClient;
use ImageMosaic\CacheManager;
use ImageMosaic\ImageUrlMapper;

$config = include __DIR__ . '/config.php';
$client = new PhotoPrismClient($config);
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'tiles';
$debugMode = filter_var($_GET['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);
$mosaicColumns = (int) ($config['mosaic_columns'] ?? 12);
$mosaicRows = (int) ($config['mosaic_rows'] ?? 12);

// OPTIMIZATION: Fetch 144 photos but return paginated tiles
// First request returns 36 tiles (3 rows), rest lazy-loaded on demand
$maxLimit = min((int) ($config['mosaic_limit'] ?? 144), $mosaicColumns * $mosaicRows);

// For API pagination: support both ?limit and ?offset
$requestLimit = (int) ($_GET['limit'] ?? 36); // How many to return
$requestOffset = (int) ($_GET['offset'] ?? 0); // Start position

// Always fetch full 144, but only return requested portion
$limit = $maxLimit; // Fetch ALL 144 photos
$returnLimit = min($requestLimit, $maxLimit - $requestOffset); // But return only what requested

if ($action === 'tiles') {
    $responseDebug = [];
    if ($debugMode) {
        $responseDebug['connection'] = $client->getConnectionDebug();
    }

    $tilesCacheKey = 'tiles_' . md5(($_GET['category'] ?? 'all') . '|' . ($_GET['album'] ?? 'all') . '|' . ($_GET['order'] ?? 'newest'));
    $tilesCachePath = 'public/cache/.' . $tilesCacheKey . '_processed.json';
    // Short TTL (5 min) for within same session
    $tilesCacheTTL = 300;
    
    // Check for cached PROCESSED tiles (skip all the expensive processing!)
    $cachedTiles = null;
    if (file_exists($tilesCachePath)) {
        $stat = stat($tilesCachePath);
        if ($stat && (time() - $stat['mtime']) < $tilesCacheTTL) {
            $cachedTiles = json_decode(file_get_contents($tilesCachePath), true);
            if (is_array($cachedTiles)) {
                $responseDebug['cache'] = 'hit_tiles';
                // Shuffle the cached tiles so each request sees new order
                shuffle($cachedTiles);
                // Paginate the cached tiles and return
                $paginatedTiles = array_slice($cachedTiles, $requestOffset, $returnLimit);
                respondJson([
                    'columns' => $mosaicColumns,
                    'rows' => $mosaicRows,
                    'tiles' => $paginatedTiles,
                    'total' => count($cachedTiles),
                    'offset' => $requestOffset,
                    'limit' => $returnLimit,
                    'hasMore' => ($requestOffset + $returnLimit) < count($cachedTiles),
                    'debug_info' => $responseDebug,
                ], $debugMode);
                exit;
            }
        }
    }

    $photos = [];
    try {
        $photos = $client->listPhotos(
            $limit,
            $_GET['album'] ?? '',
            $_GET['category'] ?? '',
            $_GET['order'] ?? 'random'
        );
        $responseDebug['photo_count'] = is_array($photos) ? count($photos) : 0;
        $responseDebug['cache'] = 'miss';
    } catch (Throwable $e) {
        http_response_code(500);
        respondJson(['error' => $e->getMessage(), 'debug_info' => $responseDebug], $debugMode);
        exit;
    }

    if (!is_array($photos)) {
        http_response_code(500);
        respondJson([
            'error' => 'PhotoPrism API returned an unexpected response type.',
            'debug_info' => $responseDebug
        ], $debugMode);
    }

    // SPEED OPTIMIZATION: Skip expensive operations on initial request
    // Return PhotoPrism URLs directly, cache asynchronously
    $cache = new CacheManager('public/cache');
    $cachedThumbs = [];
    $thumbnailUrls = [];
    
    // FAST PATH: Check cache but don't wait for it
    // For initial load, just get PhotoPrism URLs immediately
    foreach ($photos as $photo) {
        $hash = $client->getPhotoHash($photo);
        if ($hash) {
            // Quick check: is it cached?
            $cached = $cache->getThumbnailPath($hash);
            if ($cached) {
                $cachedThumbs[$hash] = $cached;
            } else {
                // Get PhotoPrism URL (instant, no fetching yet)
                $thumbUrl = $client->getThumbnailUrl($photo, 200);
                if ($thumbUrl) {
                    $thumbnailUrls[$hash] = $thumbUrl;
                }
            }
        }
    }
    
    // ASYNC BACKGROUND CACHING: Don't block response
    // Spawn background process to cache thumbnails and generate base64
    // This makes first request fast (~2 seconds instead of 25)
    if (!empty($thumbnailUrls) && $limit <= 36) {
        // Only cache for initial small request, not for subsequent large requests
        $cacheScript = __DIR__ . '/scripts/async-cache-and-encode.php';
        if (file_exists($cacheScript)) {
            $data = json_encode(['urls' => $thumbnailUrls, 'limit' => count($thumbnailUrls)]);
            @file_put_contents('/tmp/cache_jobs.json', $data . "\n", FILE_APPEND);
        }
    }
    
    // IMMEDIATE RESPONSE: Return PhotoPrism URLs (fast!)
    // No base64, no caching wait, no slow operations
    $dataUrisByHash = $thumbnailUrls;

    // Determine which photos include embedded album data and which need detail fetch
    $tiles = [];
    $missingAlbumIds = [];
    $photoIndexById = [];
    $embeddedAlbumCount = 0;

    // Helper to get a photo identifier from a photo array (mirrors client logic)
    $getPhotoId = function (array $photo): ?string {
        foreach (['uuid', 'UUID', 'uid', 'UID', 'id', 'ID'] as $k) {
            if (!empty($photo[$k])) {
                return (string) $photo[$k];
            }
        }
        return null;
    };

    // Helper to convert an album object/entry to a title string when possible
    $mapAlbumToTitle = function ($album): ?string {
        if (is_string($album)) {
            return $album; // might be UID — fallback to UID value for now
        }
        if (!is_array($album)) {
            return null;
        }
        foreach (['Title', 'title', 'Name', 'name'] as $k) {
            if (!empty($album[$k])) {
                return (string) $album[$k];
            }
        }
        // If album has a UID but no title, return UID as a fallback
        if (!empty($album['UID'])) {
            return (string) $album['UID'];
        }
        return null;
    };

    foreach ($photos as $idx => $photo) {
        $id = $getPhotoId($photo);
        if ($id === null) {
            continue;
        }

        // If embedded album data present, we'll use it; otherwise record the id for batch details fetch.
        if (empty($photo['Albums']) && empty($photo['albums'])) {
            $missingAlbumIds[] = $id;
            $photoIndexById[$id] = $idx;
        } else {
            $embeddedAlbumCount++;
        }
    }

    $fetchedDetails = [];
    if (!empty($missingAlbumIds)) {
        $fetchedDetails = $client->fetchPhotosDetailsParallel($missingAlbumIds);
    }

    // Helper: try to extract a caption from a photo details array.
    $extractCaption = function (array $photo): ?string {
        $candidates = [
            'Description', 'description', 'Caption', 'caption',
            'Desc', 'desc', 'Notes', 'notes'
        ];

        // Check top-level keys first
        foreach ($candidates as $k) {
            if (!empty($photo[$k]) && is_string($photo[$k])) {
                return (string) $photo[$k];
            }
        }

        return null;
    };

    // Helper: try to extract a taken date from a photo details array or nested Exif.
    $extractTakenDate = function (array $photo) {
        // Prefer PhotoPrism-specific normalized fields first. "TakenAtLocal" is
        // derived from TakenAt and localised using GPS when available, so use it
        // when present for the most correct human-local date/time.
        $candidates = [
            'TakenAtLocal', 'TakenAt', 'takenAt', 'Taken', 'taken',
            'DateTimeOriginal', 'dateTaken', 'DateTaken',
            'CreatedAt', 'createdAt', 'Created', 'created',
            'Date', 'date', 'DateTime', 'dateTime'
        ];

        // Check top-level keys first
        foreach ($candidates as $k) {
            if (isset($photo[$k]) && $photo[$k] !== '') {
                return $photo[$k];
            }
        }

        // Check common EXIF containers
        foreach (['Exif', 'exif', 'Metadata', 'metadata'] as $exifKey) {
            if (!empty($photo[$exifKey]) && is_array($photo[$exifKey])) {
                foreach (['DateTimeOriginal', 'DateTime', 'Date', 'date', 'DateTimeDigitized'] as $k) {
                    if (!empty($photo[$exifKey][$k])) {
                        return $photo[$exifKey][$k];
                    }
                }
            }
        }

        return null;
    };

    // Debug info about album discovery
    $responseDebug['embedded_album_count'] = $embeddedAlbumCount;
    $responseDebug['missing_album_count'] = count($missingAlbumIds);
    $responseDebug['fetched_details_count'] = is_array($fetchedDetails) ? count($fetchedDetails) : 0;

    $urlMapper = new ImageUrlMapper();
    $urlMapper->loadMappings();

    foreach ($photos as $photo) {
        $hash = $client->getPhotoHash($photo);
        $thumb = null;

         // Priority order for thumbnail:
         // 1. Cached thumbnail URL (fastest!)
         // 2. Base64 data URI (already generated)
         // 3. PhotoPrism URL (fallback)
         if ($hash !== null && isset($cachedThumbs[$hash])) {
             // Use cached file URL - instant, no base64 encoding
             $thumb = $cachedThumbs[$hash];
         } elseif ($hash !== null && isset($dataUrisByHash[$hash])) {
             // Use generated base64
             $thumb = $dataUrisByHash[$hash];
         } else {
             // Fallback to PhotoPrism URL
             $thumb = $client->getThumbnailUrl($photo);
         }

         if ($thumb === null) {
             $responseDebug['skipped_photos'] = ($responseDebug['skipped_photos'] ?? 0) + 1;
             continue;
         }

        // Prefer embedded album objects when available
        $albumTitles = [];
        if (!empty($photo['Albums']) && is_array($photo['Albums'])) {
            $albumTitles = array_values(array_filter(array_map($mapAlbumToTitle, $photo['Albums'])));
        } elseif (!empty($photo['albums']) && is_array($photo['albums'])) {
            $albumTitles = array_values(array_filter(array_map($mapAlbumToTitle, $photo['albums'])));
        } else {
            // Fallback to fetched details (parallel) if available
            $id = $getPhotoId($photo);
            if ($id !== null && isset($fetchedDetails[$id]) && is_array($fetchedDetails[$id])) {
                $pd = $fetchedDetails[$id];
                if (!empty($pd['Albums']) && is_array($pd['Albums'])) {
                    $albumTitles = array_values(array_filter(array_map($mapAlbumToTitle, $pd['Albums'])));
                }
            }
        }

        // Determine a human-friendly taken date when available. Prefer explicit fields,
        // then Exif, then any fetched details.
        $takenRaw = $extractTakenDate($photo);
        $idForDetails = $getPhotoId($photo);
        if (($takenRaw === null || $takenRaw === '') && $idForDetails !== null && isset($fetchedDetails[$idForDetails]) && is_array($fetchedDetails[$idForDetails])) {
            $takenRaw = $extractTakenDate($fetchedDetails[$idForDetails]);
        }

        $takenFormatted = '';
        if ($takenRaw !== null && $takenRaw !== '') {
            $dt = null;

            if (is_numeric($takenRaw)) {
                // Numeric could be seconds or milliseconds since epoch
                $num = (string) $takenRaw;
                $ts = (int) $takenRaw;
                if (strlen($num) > 12) { // likely milliseconds
                    $ts = (int) floor($ts / 1000);
                }
                try {
                    $dt = (new DateTimeImmutable())->setTimestamp($ts);
                } catch (\Throwable $e) {
                    $dt = null;
                }
            } else {
                // Try native parsing (ISO 8601, RFC3339, etc.)
                try {
                    $dt = new DateTimeImmutable((string) $takenRaw);
                } catch (\Throwable $e) {
                    $dt = null;
                }

                // Try fixing common EXIF format YYYY:MM:DD HH:MM:SS -> YYYY-MM-DD HH:MM:SS
                if ($dt === null && preg_match('/^(\d{4}:\d{2}:\d{2})([ T])(\d{2}:\d{2}:\d{2})/', $takenRaw, $m)) {
                    $norm = str_replace(':', '-', $m[1]) . ' ' . $m[3];
                    try {
                        $dt = new DateTimeImmutable($norm);
                    } catch (\Throwable $e) {
                        $dt = null;
                    }
                }

                // Fallback to strtotime()
                if ($dt === null) {
                    $ts2 = @strtotime((string) $takenRaw);
                    if ($ts2 !== false && $ts2 !== -1) {
                        try {
                            $dt = (new DateTimeImmutable())->setTimestamp((int) $ts2);
                        } catch (\Throwable $e) {
                            $dt = null;
                        }
                    }
                }
            }

            if ($dt !== null) {
                // Format as a friendly date (date only). Preserve the photo's timezone
                // if the parsed string included one.
                $takenFormatted = $dt->format('F jS, Y');
            } else {
                $takenFormatted = trim((string) $takenRaw);
            }
        }

        // Extract caption from photo or fetched details
        $caption = $extractCaption($photo);
        if (($caption === null || $caption === '') && $idForDetails !== null && isset($fetchedDetails[$idForDetails]) && is_array($fetchedDetails[$idForDetails])) {
            $caption = $extractCaption($fetchedDetails[$idForDetails]);
        }

        // Get image URLs for different sizes
        $fullImageUrl = $client->getThumbnailUrl($photo, 2000) ?? $client->getThumbnailUrl($photo, 500) ?? $thumb;
        $mediumImageUrl = $client->getThumbnailUrl($photo, 500) ?? $fullImageUrl;
        $thumbnailImageUrl = $client->getThumbnailUrl($photo, 224) ?? $mediumImageUrl;

        // Map URLs to hashes for privacy
        $fullImageHash = $urlMapper->mapUrl($fullImageUrl);
        $mediumImageHash = $urlMapper->mapUrl($mediumImageUrl);
        $thumbnailHash = $urlMapper->mapUrl($thumbnailImageUrl);

        $tiles[] = [
            'title' => $photo['Title'] ?? $photo['title'] ?? '',
            'albums' => $albumTitles,
            'thumb' => $thumbnailHash,
            //'thumbUrl' => $thumbnailImageUrl,
            //'mediumUrl' => $mediumImageUrl,
            //'fullUrl' => $fullImageUrl,
            'imageHash' => $fullImageHash,
            'mediumHash' => $mediumImageHash,
            'taken' => $takenFormatted,
            'caption' => $caption ?? '',
        ];
    }

    while (count($tiles) < $limit) {
        $tiles[] = [
            'title' => 'Empty slot',
            'albums' => [],
            'thumb' => 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">' .
                '<rect width="100%" height="100%" fill="#333"/>' .
                '<text x="50%" y="50%" fill="#aaa" font-family="Arial,Helvetica,sans-serif" ' .
                'font-size="20" text-anchor="middle" dominant-baseline="middle">No image</text>' .
                '</svg>'
            ),
            'full' => '#',
            'taken' => '',
            'caption' => '',
        ];
    }

    // Shuffle tiles before caching so subsequent reads get a new order
    shuffle($tiles);
    // Cache processed tiles for 5 minutes to avoid expensive re-processing
    @mkdir('public/cache', 0755, true);
    $urlMapper->persistMappings();
    @file_put_contents($tilesCachePath, json_encode($tiles));

    // Return paginated results for fast initial load
    // Support offset/limit for lazy-loading remaining tiles
    $paginatedTiles = array_slice($tiles, $requestOffset, $returnLimit);
    $totalTiles = count($tiles);
    
    respondJson([
        'columns' => $mosaicColumns,
        'rows' => $mosaicRows,
        'tiles' => $paginatedTiles,
        'total' => $totalTiles,           // Total number of tiles available
        'offset' => $requestOffset,       // Current offset
        'limit' => $returnLimit,          // Number returned
        'hasMore' => ($requestOffset + $returnLimit) < $totalTiles, // Are there more?
        'debug_info' => $responseDebug,
    ], $debugMode);
}

if ($action === 'albums_by_category') {
    $responseDebug = [];
    if ($debugMode) {
        $responseDebug['connection'] = $client->getConnectionDebug();
    }

    $category = $_GET['category'] ?? '';

    if (empty($category)) {
        http_response_code(400);
        respondJson(['error' => 'Category parameter is required.', 'debug_info' => $responseDebug], $debugMode);
    }

    try {
        $albums = $client->getAlbumsByCategory($category);
        respondJson([
            'albums' => $albums,
            'debug_info' => $responseDebug,
        ], $debugMode);
    } catch (Throwable $e) {
        http_response_code(500);
        respondJson(['error' => $e->getMessage(), 'debug_info' => $responseDebug], $debugMode);
    }
}

if ($action === 'debug') {
    respondJson([
        'connection' => $client->getConnectionDebug(),
        'lastRequest' => $client->getLastRequestDebug(),
    ], $debugMode);
}

if ($action === 'config') {
    respondJson([
        'baseUrl' => $config['photo_prism_base_url'],
        'mosaicColumns' => $mosaicColumns,
        'mosaicRows' => $mosaicRows,
    ], $debugMode);
}

if ($action === 'photo-count') {
    $responseDebug = [];
    if ($debugMode) {
        $responseDebug['connection'] = $client->getConnectionDebug();
    }

    // Cache photo count to keep response under 2 seconds
    $countCacheKey = 'count_' . md5(($_GET['category'] ?? 'all') . '|' . ($_GET['album'] ?? 'all'));
    $countCachePath = 'public/cache/.' . $countCacheKey . '.json';
    $countCacheTTL = 3600;
    
    $cachedCount = null;
    if (file_exists($countCachePath)) {
        $stat = stat($countCachePath);
        if ($stat && (time() - $stat['mtime']) < $countCacheTTL) {
            $cachedCount = json_decode(file_get_contents($countCachePath), true);
            $responseDebug['cache'] = 'hit';
        }
    }

    try {
        if ($cachedCount !== null) {
            $count = $cachedCount;
        } else {
            $count = $client->getPhotoCount(
                $_GET['album'] ?? '',
                $_GET['category'] ?? ''
            );
            $responseDebug['cache'] = 'miss';
            
            @mkdir('public/cache', 0755, true);
            @file_put_contents($countCachePath, json_encode($count));
        }
        
        respondJson([
            'count' => $count,
            'debug_info' => $responseDebug,
        ], $debugMode);
    } catch (Throwable $e) {
        http_response_code(500);
        respondJson(['error' => $e->getMessage(), 'debug_info' => $responseDebug], $debugMode);
    }
}

if ($action === 'featured-photo') {
    $responseDebug = [];
    if ($debugMode) {
        $responseDebug['connection'] = $client->getConnectionDebug();
    }

    $category = $_GET['category'] ?? '';
    $album = $_GET['album'] ?? '';

    if (empty($category) && empty($album)) {
        http_response_code(400);
        respondJson(['error' => 'Either category or album parameter is required.', 'debug_info' => $responseDebug], $debugMode);
        exit;
    }

    try {
        $photo = $client->getFeaturedPhoto($album, $category);
        if ($photo === null) {
            http_response_code(404);
            respondJson(['error' => 'No photos found for the specified category/album.', 'debug_info' => $responseDebug], $debugMode);
            exit;
        }

        $thumbUrl = $client->getThumbnailUrl($photo, 500);
        respondJson([
            'photo' => $photo,
            'thumbnail_url' => $thumbUrl,
            'debug_info' => $responseDebug,
        ], $debugMode);
    } catch (Throwable $e) {
        http_response_code(500);
        respondJson(['error' => $e->getMessage(), 'debug_info' => $responseDebug], $debugMode);
    }
}

if ($action === 'cache') {
    $cache = new CacheManager('public/cache');
    $urlMapper = new ImageUrlMapper();
    $urlMapper->loadMappings();
    
    $subaction = $_GET['subaction'] ?? 'get';
    $responseDebug = [];

    // GET: Fetch and cache image by hash (URL is looked up internally)
    if ($subaction === 'get') {
        $imageHash = $_GET['hash'] ?? '';
        if (empty($imageHash)) {
            http_response_code(400);
            respondJson(['error' => 'Hash parameter required'], $debugMode);
            exit;
        }
        
        // Look up actual PhotoPrism URL from hash
        $photoUrl = $urlMapper->getUrlFromHash($imageHash);
        
        // If hash not found, force refresh tiles cache and try again
        if (!$photoUrl) {
            // Hash not found in current mapping - cache may be stale
            // Force refresh by deleting tiles cache files
            $cacheDir = 'public/cache';
            $tilesCachePattern = glob($cacheDir . '/.tiles_*_processed.json');
            if (is_array($tilesCachePattern) && !empty($tilesCachePattern)) {
                foreach ($tilesCachePattern as $cacheFile) {
                    @unlink($cacheFile);
                }
                // Reload mappings from fresh cache
                $urlMapper->clearMappings();
                $urlMapper->loadMappings();
                
                // Try lookup again
                $photoUrl = $urlMapper->getUrlFromHash($imageHash);
                
                if ($photoUrl) {
                    if ($debugMode) {
                        $responseDebug['cache_action'] = 'refreshed_tiles_cache';
                    }
                } else {
                    // Still not found after refresh - hash truly doesn't exist
                    http_response_code(404);
                    respondJson(['error' => 'Image hash not found after cache refresh'], $debugMode);
                    exit;
                }
            } else {
                http_response_code(404);
                respondJson(['error' => 'Image hash not found'], $debugMode);
                exit;
            }
        }

        try {
            $content = $cache->get($photoUrl, 30 * 24 * 60 * 60); // 30 days TTL
            $metadata = $cache->getMetadata($photoUrl);
            
            // Detect MIME type from file extension or content
            $ext = strtolower(pathinfo($photoUrl, PATHINFO_EXTENSION));
            $mimeType = 'application/octet-stream';
            
            $mimeTypes = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'bmp' => 'image/bmp',
            ];
            
            if (isset($mimeTypes[$ext])) {
                $mimeType = $mimeTypes[$ext];
            } else {
                // Fallback: try to detect from image data
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_buffer($finfo, $content) ?: $mimeType;
                finfo_close($finfo);
            }

            // Optimize headers for faster delivery
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: public, max-age=2592000, immutable'); // 30 days + immutable
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT');
            header('X-Content-Type-Options: nosniff');
            header('X-Cache-Status: hit');
            
            // Don't compress image responses - images are already compressed
            // and re-compression can cause issues with blob URLs
            header('Content-Encoding: identity');
            
            if ($metadata) {
                header('X-Cache-Age: ' . $metadata['age_seconds']);
                header('X-Cache-Size: ' . $metadata['size']);
            }
            
            // Stream content with output buffering disabled for faster delivery
            if (!ob_get_level()) {
                ob_start(null, 0, PHP_OUTPUT_HANDLER_FLUSHABLE);
            }
            echo $content;
            flush();
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            respondJson(['error' => 'Failed to cache image: ' . $e->getMessage()], $debugMode);
            exit;
        }
    }

    // INFO: Get cache metadata by hash
    if ($subaction === 'info') {
        $imageHash = $_GET['hash'] ?? '';
        if (empty($imageHash)) {
            http_response_code(400);
            respondJson(['error' => 'Hash parameter required'], $debugMode);
            exit;
        }

        $photoUrl = $urlMapper->getUrlFromHash($imageHash);
        
        // If hash not found, force refresh tiles cache and try again
        if (!$photoUrl) {
            // Hash not found in current mapping - cache may be stale
            // Force refresh by deleting tiles cache files
            $cacheDir = 'public/cache';
            $tilesCachePattern = glob($cacheDir . '/.tiles_*_processed.json');
            if (is_array($tilesCachePattern) && !empty($tilesCachePattern)) {
                foreach ($tilesCachePattern as $cacheFile) {
                    @unlink($cacheFile);
                }
                // Reload mappings from fresh cache
                $urlMapper->clearMappings();
                $urlMapper->loadMappings();
                
                // Try lookup again
                $photoUrl = $urlMapper->getUrlFromHash($imageHash);
                
                if ($photoUrl) {
                    if ($debugMode) {
                        $responseDebug['cache_action'] = 'refreshed_tiles_cache';
                    }
                } else {
                    // Still not found after refresh - hash truly doesn't exist
                    http_response_code(404);
                    respondJson(['error' => 'Image hash not found after cache refresh'], $debugMode);
                    exit;
                }
            } else {
                http_response_code(404);
                respondJson(['error' => 'Image hash not found'], $debugMode);
                exit;
            }
        }

        $metadata = $cache->getMetadata($photoUrl);
        if ($metadata === null) {
            http_response_code(404);
            respondJson(['error' => 'Image not in cache', 'cached' => false], $debugMode);
            exit;
        }

        respondJson(['cached' => true, 'metadata' => $metadata, 'debug_info' => $responseDebug], $debugMode);
        exit;
    }

    // STATS: Get cache statistics
    if ($subaction === 'stats') {
        $stats = $cache->getStats();
        respondJson(['stats' => $stats, 'debug_info' => $responseDebug], $debugMode);
        exit;
    }

    // CLEANUP: Remove expired cache files
    if ($subaction === 'cleanup') {
        $removed = $cache->cleanup();
        respondJson(['removed' => $removed, 'debug_info' => $responseDebug], $debugMode);
        exit;
    }

    // FLUSH: Clear all cache
    if ($subaction === 'flush') {
        $removed = $cache->flush();
        respondJson(['removed' => $removed, 'debug_info' => $responseDebug], $debugMode);
        exit;
    }

    http_response_code(400);
    respondJson(['error' => 'Unknown cache subaction'], $debugMode);
    exit;
}

http_response_code(404);
respondJson(['error' => 'Unknown action'], $debugMode);
