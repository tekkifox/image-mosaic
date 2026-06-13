<?php
require __DIR__ . '/config.php';
require __DIR__ . '/photoprism_client.php';

$config = include __DIR__ . '/config.php';
$client = new PhotoPrismClient($config);
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'tiles';
$debugMode = filter_var($_GET['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);
$mosaicColumns = (int) ($config['mosaic_columns'] ?? 12);
$mosaicRows = (int) ($config['mosaic_rows'] ?? 12);
$limit = min((int) ($config['mosaic_limit'] ?? 144), $mosaicColumns * $mosaicRows);

function respondJson(array $data, bool $debugMode = false): void
{
    if ($debugMode) {
        $data['debug'] = [
            'timestamp' => date('c'),
            'requestUri' => $_SERVER['REQUEST_URI'] ?? '',
            'phpVersion' => PHP_VERSION,
        ];
    }

    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'tiles') {
    $responseDebug = [];
    if ($debugMode) {
        $responseDebug['connection'] = $client->getConnectionDebug();
    }

    try {
        $photos = $client->listPhotos($limit);
        $responseDebug['photo_count'] = is_array($photos) ? count($photos) : 0;
    } catch (Throwable $e) {
        http_response_code(500);
        respondJson(['error' => $e->getMessage(), 'debug_info' => $responseDebug], $debugMode);
    }

    if (!is_array($photos)) {
        http_response_code(500);
        respondJson(['error' => 'PhotoPrism API returned an unexpected response type.', 'debug_info' => $responseDebug], $debugMode);
    }

    $tiles = [];
    foreach ($photos as $photo) {
        // Fetch thumbnail and embed as a data URI so the browser does not need auth headers.
        $thumbDataUri = $client->fetchThumbnailDataUri($photo, 200);
        if ($thumbDataUri !== null) {
            $tiles[] = [
                'title' => $photo['Title'] ?? $photo['title'] ?? '',
                'thumb' => $thumbDataUri,
                'link' => $client->getPhotoPageUrl($photo),
            ];
            continue;
        }

        // Fallback to returning the direct thumbnail URL if fetching failed.
        $thumbUrl = $client->getThumbnailUrl($photo);
        if ($thumbUrl === null) {
            $responseDebug['skipped_photos'] = ($responseDebug['skipped_photos'] ?? 0) + 1;
            continue;
        }

        $tiles[] = [
            'title' => $photo['Title'] ?? $photo['title'] ?? '',
            'thumb' => $thumbUrl,
            'link' => $client->getPhotoPageUrl($photo),
        ];
    }

    while (count($tiles) < $limit) {
        $tiles[] = [
            'title' => 'Empty slot',
            'thumb' => 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="100%" height="100%" fill="#333"/><text x="50%" y="50%" fill="#aaa" font-family="Arial,Helvetica,sans-serif" font-size="20" text-anchor="middle" dominant-baseline="middle">No image</text></svg>'),
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
