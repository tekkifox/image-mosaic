<?php

require __DIR__ . '/config.php';
require __DIR__ . '/photoprism_client.php';
require __DIR__ . '/includes/functions.php';

use function ImageMosaic\respondJson;
use ImageMosaic\PhotoPrismClient;

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
        $photos = $client->listPhotos($limit);
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

        $tiles[] = [
            'title' => $photo['Title'] ?? $photo['title'] ?? '',
            'albums' => $albumTitles,
            'thumb' => $thumb,
            'link' => $client->getPhotoPageUrl($photo),
        ];
    }

    while (count($tiles) < $limit) {
        $tiles[] = [
            'title' => 'Empty slot',
            'thumb' => 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">' .
                '<rect width="100%" height="100%" fill="#333"/>' .
                '<text x="50%" y="50%" fill="#aaa" font-family="Arial,Helvetica,sans-serif" ' .
                'font-size="20" text-anchor="middle" dominant-baseline="middle">No image</text>' .
                '</svg>'
            ),
            'link' => '#',
        ];
    }

    respondJson([
        'columns' => $mosaicColumns,
        'rows' => $mosaicRows,
        'tiles' => array_slice($tiles, 0, $limit),
        'debug_info' => $responseDebug,
    ], $debugMode);
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

http_response_code(404);
respondJson(['error' => 'Unknown action'], $debugMode);
