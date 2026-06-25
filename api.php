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
$limit = min((int) ($config['mosaic_limit'] ?? 144), $mosaicColumns * $mosaicRows);

if ($action === 'tiles') {
    $responseDebug = [];
    if ($debugMode) {
        $responseDebug['connection'] = $client->getConnectionDebug();
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
    } catch (Throwable $e) {
        http_response_code(500);
        respondJson(['error' => $e->getMessage(), 'debug_info' => $responseDebug], $debugMode);
    }

    if (!is_array($photos)) {
        http_response_code(500);
        respondJson([
            'error' => 'PhotoPrism API returned an unexpected response type.',
            'debug_info' => $responseDebug
        ], $debugMode);
    }

    $dataUrisByHash = $client->fetchThumbnailsDataUriParallel($photos, 200);

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
        // Fetch missing photo details in parallel to avoid sequential API calls.
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

    foreach ($photos as $photo) {
        $hash = $client->getPhotoHash($photo);
        $thumb = null;

        if ($hash !== null && isset($dataUrisByHash[$hash])) {
            $thumb = $dataUrisByHash[$hash];
        } else {
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

        // Get full-resolution image URL for lightbox (fit_5120 for maximum quality)
        $fullImageUrl = $client->getThumbnailUrl($photo, 2000) ?? $client->getThumbnailUrl($photo, 500) ?? $thumb;
        
        // Map URLs to hashes for privacy (hide PhotoPrism URLs from frontend)
        // Frontend will use these hashes to request images, backend will lookup the URLs
        $urlMapper = new ImageUrlMapper();
        $urlMapper->loadMappings();
        
        // Hash for full-resolution image (for lightbox) - size 2000 gives fit_5120
        $fullImageHash = $urlMapper->mapUrl($fullImageUrl);
        
        // Hash for medium-resolution image (for prefetch optimization) - size 500 gives tile_500
        $mediumImageUrl = $client->getThumbnailUrl($photo, 500) ?? $fullImageUrl;
        $mediumImageHash = $urlMapper->mapUrl($mediumImageUrl);
        
        $urlMapper->persistMappings();

        $tiles[] = [
            'title' => $photo['Title'] ?? $photo['title'] ?? '',
            'albums' => $albumTitles,
            'thumb' => $thumb,
            'imageHash' => $fullImageHash,    // ← Full-size hash for lightbox
            'mediumHash' => $mediumImageHash, // ← Medium hash for prefetch (faster)
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

    respondJson([
        'columns' => $mosaicColumns,
        'rows' => $mosaicRows,
        'tiles' => array_slice($tiles, 0, $limit),
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

    try {
        $count = $client->getPhotoCount(
            $_GET['album'] ?? '',
            $_GET['category'] ?? ''
        );
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
        if (!$photoUrl) {
            http_response_code(404);
            respondJson(['error' => 'Image hash not found'], $debugMode);
            exit;
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
        if (!$photoUrl) {
            http_response_code(404);
            respondJson(['error' => 'Image hash not found'], $debugMode);
            exit;
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
