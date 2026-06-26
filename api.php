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

    // Thumbnail caching has been removed; rely on live PhotoPrism URLs directly.

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
        $fullImageUrl = $client->getThumbnailUrl($photo, 2000) ?? $client->getThumbnailUrl($photo, 500) ?? $client->getThumbnailUrl($photo, 224);
        $mediumImageUrl = $client->getThumbnailUrl($photo, 500) ?? $fullImageUrl;
        $thumbnailImageUrl = $client->getThumbnailUrl($photo, 224) ?? $mediumImageUrl;
        $thumbUrl = $thumbnailImageUrl ?? $mediumImageUrl ?? $fullImageUrl;

        if ($thumbUrl === null) {
            $responseDebug['skipped_photos'] = ($responseDebug['skipped_photos'] ?? 0) + 1;
            continue;
        }

        // Map URLs to hashes for privacy
        $fullImageHash = $fullImageUrl ? $urlMapper->mapUrl($fullImageUrl) : null;
        $mediumImageHash = $mediumImageUrl ? $urlMapper->mapUrl($mediumImageUrl) : null;

        $tiles[] = [
            'title' => $photo['Title'] ?? $photo['title'] ?? '',
            'albums' => $albumTitles,
            'thumbUrl' => $thumbUrl,
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
            'thumbUrl' => 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">' .
                '<rect width="100%" height="100%" fill="#333"/>' .
                '<text x="50%" y="50%" fill="#aaa" font-family="Arial,Helvetica,sans-serif" ' .
                'font-size="20" text-anchor="middle" dominant-baseline="middle">No image</text>' .
                '</svg>'
            ),
            'imageHash' => '',
            'mediumHash' => '',
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

if ($action === 'country-places') {
    $responseDebug = [];
    if ($debugMode) {
        $responseDebug['connection'] = $client->getConnectionDebug();
    }

    $placesCacheKey = 'country_places_v9_' . md5(($_GET['category'] ?? 'all') . '|' . ($_GET['album'] ?? 'all') . '|' . ($_GET['order'] ?? 'newest'));
    $placesCachePath = 'public/cache/.' . $placesCacheKey . '.json';
    $placesCacheTTL = 3600;
    $translationCachePath = 'public/cache/.country_place_translations_v1.json';

    if (file_exists($placesCachePath)) {
        $stat = stat($placesCachePath);
        if ($stat && (time() - $stat['mtime']) < $placesCacheTTL) {
            $cached = json_decode(file_get_contents($placesCachePath), true);
            if (is_array($cached)) {
                respondJson($cached, $debugMode);
                exit;
            }
        }
    }

    try {
        $photos = $client->listPhotos(
            $maxLimit,
            $_GET['album'] ?? '',
            $_GET['category'] ?? '',
            $_GET['order'] ?? 'random'
        );
        $responseDebug['photo_count'] = is_array($photos) ? count($photos) : 0;
    } catch (Throwable $e) {
        http_response_code(500);
        respondJson(['error' => $e->getMessage(), 'debug_info' => $responseDebug], $debugMode);
        exit;
    }

    if (!is_array($photos) || empty($photos)) {
        respondJson([
            'countries' => [],
            'debug_info' => $responseDebug,
        ], $debugMode);
        exit;
    }

    $getPhotoId = function (array $photo): ?string {
        foreach (['uuid', 'UUID', 'uid', 'UID', 'id', 'ID'] as $k) {
            if (!empty($photo[$k])) {
                return (string) $photo[$k];
            }
        }
        return null;
    };

    $extractStringValue = function (array $source, array $keys): ?string {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];
            if (is_string($value) || is_numeric($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    };

    $normalizePlaceText = function (?string $text): ?string {
        if ($text === null) {
            return null;
        }

        $value = trim($text);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    };
    $translationCache = [];
    if (file_exists($translationCachePath)) {
        $cachedTranslations = json_decode((string) @file_get_contents($translationCachePath), true);
        if (is_array($cachedTranslations)) {
            $translationCache = $cachedTranslations;
        }
    }

    $translatePlaceTextToEnglish = function (?string $text) use ($normalizePlaceText, &$translationCache): ?string {
        $value = $normalizePlaceText($text);
        if ($value === null) {
            return null;
        }

        if (isset($translationCache[$value]) && is_string($translationCache[$value])) {
            return $translationCache[$value];
        }

        $url = 'https://translate.googleapis.com/translate_a/single?' . http_build_query([
            'client' => 'gtx',
            'sl' => 'auto',
            'tl' => 'en',
            'dt' => 't',
            'q' => $value,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: en',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($response, true);
            $translated = $decoded[0][0][0] ?? null;
            if (is_string($translated) && trim($translated) !== '') {
                $value = trim(preg_replace('/\s+/u', ' ', $translated));
            }
        }

        $translationCache[$value] = $value;
        return $value;
    };

    $countryToFullName = function (string $country) use ($normalizePlaceText): string {
        $value = trim($country);
        if ($value === '') {
            return $value;
        }

        $normalized = strtoupper($value);
        if (preg_match('/^[A-Z]{2}$/', $normalized)) {
            $fullName = Locale::getDisplayRegion('und-' . $normalized, 'en');
            if (is_string($fullName) && $fullName !== '' && $fullName !== $normalized) {
                return $normalizePlaceText($fullName) ?? $fullName;
            }
        }

        return $normalizePlaceText($value) ?? $value;
    };

    $stripCountryFromPlace = function (?string $label, ?string $country) use ($normalizePlaceText): ?string {
        $place = $normalizePlaceText($label);

        if ($place === null || $place === '') {
            return $place;
        }

        $countryName = $normalizePlaceText($country);
        if ($countryName === null || $countryName === '') {
            return $place;
        }

        $placeLower = mb_strtolower($place);
        $countryLower = mb_strtolower($countryName);

        $suffixes = [
            ', ' . $countryLower,
            ' - ' . $countryLower,
            ' ' . $countryLower,
        ];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($placeLower, $suffix)) {
                $trimmed = trim(substr($place, 0, strlen($place) - strlen($suffix)));
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return $place;
    };

    $extractLocation = function (array $photo, ?array $details = null) use ($extractStringValue, $countryToFullName, $normalizePlaceText): ?array {
        $sources = [];
        if (is_array($details)) {
            $sources[] = $details;
        }
        $sources[] = $photo;

        $placeSources = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $placeSources[] = $source;
            foreach (['place', 'Place', 'location', 'Location'] as $nestedKey) {
                if (!empty($source[$nestedKey]) && is_array($source[$nestedKey])) {
                    $placeSources[] = $source[$nestedKey];
                }
            }
        }

        foreach ($placeSources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $placeNode = $source;
            foreach (['Place', 'place', 'Location', 'location'] as $nestedKey) {
                if (!empty($source[$nestedKey]) && is_array($source[$nestedKey])) {
                    $placeNode = $source[$nestedKey];
                    break;
                }
            }

            $label = $normalizePlaceText($extractStringValue($placeNode, ['Label', 'label', 'PlaceLabel', 'placeLabel']));
            $city = $normalizePlaceText($extractStringValue($placeNode, ['City', 'city', 'PlaceCity', 'placeCity']));
            $state = $normalizePlaceText($extractStringValue($placeNode, ['State', 'state', 'Region', 'region', 'PlaceState', 'placeState']));
            $country = $extractStringValue($placeNode, ['Country', 'country', 'PlaceCountry', 'placeCountry']);

            if ($label === null || $label === '') {
                $pieces = array_values(array_filter([$city, $state, $country], fn($v) => $v !== null && $v !== ''));
                if (!empty($pieces)) {
                    $label = implode(', ', array_values(array_unique($pieces)));
                }
            }

            if (($country === null || $country === '') && $label !== null && str_contains($label, ',')) {
                $parts = array_values(array_filter(array_map('trim', explode(',', $label))));
                if (!empty($parts)) {
                    $country = $parts[count($parts) - 1];
                }
            }

            if ($country !== null && $country !== '') {
                $country = $countryToFullName($country);
            }

            if ($country !== null && $country !== '') {
                $country = $normalizePlaceText($country) ?? $country;
            }

            if ($label !== null && $label !== '' && $country !== null && $country !== '') {
                return [
                    'country' => trim($country),
                    'place' => trim($label),
                ];
            }
        }

        return null;
    };

    $photoIds = [];
    foreach ($photos as $photo) {
        $id = $getPhotoId($photo);
        if ($id !== null) {
            $photoIds[] = $id;
        }
    }

    $photoIds = array_values(array_unique($photoIds));

    $fetchedDetails = !empty($photoIds) ? $client->fetchPhotosDetailsParallel($photoIds) : [];

    $countries = [];
    $excludedCountries = ['hungary', 'france', 'unknown region'];
    foreach ($photos as $photo) {
        $id = $getPhotoId($photo);
        $details = ($id !== null && isset($fetchedDetails[$id]) && is_array($fetchedDetails[$id])) ? $fetchedDetails[$id] : null;
        $location = $extractLocation($photo, $details);

        if ($location === null) {
            continue;
        }

        $countryLabel = trim($location['country']);
        $placeLabel = trim($location['place']);
        if ($countryLabel === '' || $placeLabel === '') {
            continue;
        }

        if (in_array(strtolower($countryLabel), $excludedCountries, true)) {
            continue;
        }

        $countryKey = strtolower($countryLabel);
        if (!isset($countries[$countryKey])) {
            $countries[$countryKey] = [
                'country' => $countryLabel,
                'photoCount' => 0,
                'places' => [],
            ];
        }

        $countries[$countryKey]['photoCount']++;
        $placeKey = strtolower($placeLabel);
        if (!isset($countries[$countryKey]['places'][$placeKey])) {
            $countries[$countryKey]['places'][$placeKey] = [
                'name' => $placeLabel,
                'count' => 0,
            ];
        }
        $countries[$countryKey]['places'][$placeKey]['count']++;
    }

    $countryList = [];
    foreach ($countries as $country) {
        $places = array_values($country['places']);
        usort($places, static function (array $a, array $b): int {
            if ($a['count'] === $b['count']) {
                return strcmp($a['name'], $b['name']);
            }
            return $b['count'] <=> $a['count'];
        });

        $translatedPlaces = [];
        foreach (array_slice($places, 0, 6) as $place) {
            $translated = $translatePlaceTextToEnglish($place['name']);
            $translated = $stripCountryFromPlace($translated, $country['country']);
            $translatedPlaces[] = [
                'name' => $translated !== null && $translated !== '' ? $translated : $place['name'],
                'count' => $place['count'],
            ];
        }

        $countryList[] = [
            'country' => $country['country'],
            'photoCount' => $country['photoCount'],
            'places' => $translatedPlaces,
        ];
    }

    usort($countryList, static function (array $a, array $b): int {
        if ($a['photoCount'] === $b['photoCount']) {
            return strcmp($a['country'], $b['country']);
        }
        return $b['photoCount'] <=> $a['photoCount'];
    });

    $payload = [
        'countries' => $countryList,
        'debug_info' => $responseDebug,
    ];

    @mkdir('public/cache', 0755, true);
    @file_put_contents($placesCachePath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @file_put_contents($translationCachePath, json_encode($translationCache, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    respondJson($payload, $debugMode);
    exit;
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
