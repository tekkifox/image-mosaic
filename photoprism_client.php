<?php

declare(strict_types=1);

namespace ImageMosaic;

use InvalidArgumentException;
use CurlHandle;

class PhotoPrismClient
{
    // --- API Endpoints and Configuration Keys ---
    private const API_VERSION_PATH = '/api/v1';
    private const PHOTOS_ENDPOINT = '/photos';
    private const THUMBNAIL_PATH = '/api/v1/t';

    private const CONFIG_BASE_URL = 'photo_prism_base_url';
    private const CONFIG_ACCESS_TOKEN = 'photo_prism_access_token';

    // --- Default cURL Options ---
    private const DEFAULT_TIMEOUT = 15;
    private const DEFAULT_CONNECT_TIMEOUT = 10;
    private const THUMBNAIL_REQUEST_TIMEOUT = 15; // Specific timeout for thumbnail fetches

    // --- Class Properties ---
    private string $baseUrl;
    private string $accessToken;
    private string $cacheDir = 'public/cache';
    private const CACHE_TTL = 3600; // 1 hour cache for albums
    private ?string $previewToken = null;
    private array $lastRequestDebug = [];

    /**
     * Constructor initializes the client with configuration array.
     */
    public function __construct(array $config)
    {
        // Use throw exceptions for missing critical config values instead of assigning empty strings,
        // as this enforces configuration correctness early.
        $this->baseUrl = rtrim($config[self::CONFIG_BASE_URL] ?? '', '/');
        $this->accessToken = $config[self::CONFIG_ACCESS_TOKEN] ?? '';

        if (empty($this->baseUrl)) {
            throw new InvalidArgumentException('Base URL must be configured.');
        }
    }

    public function getConnectionDebug(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'hasAccessToken' => $this->accessToken !== '',
            'authType' => $this->getAuthType(),
            'timeout' => self::DEFAULT_TIMEOUT,
            'connectTimeout' => self::DEFAULT_CONNECT_TIMEOUT,
        ];
    }

    private function getAuthType(): string
    {
        if ($this->accessToken !== '') {
            return 'access_token';
        }

        return 'none';
    }

    public function listPhotos(
        int $limit = 144,
        string $album = '',
        string $category = '',
        string $order = 'random'
    ): array {
        // Cache key based on search parameters
        $cacheKey = 'photos_' . md5(json_encode([$limit, $album, $category, $order]));
        $cached = $this->getCached($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = ['limit' => $limit, 'order' => $order, 'count' => $limit];

        $albumUids = [];
        $albumTitles = [];

        if (!empty($category)) {
            $categoryAlbums = $this->getAlbumsByCategory($category, 100);
            foreach ($categoryAlbums as $catAlbum) {
                if (!empty($catAlbum['UID'])) {
                    $albumUids[] = $catAlbum['UID'];
                    $albumTitles[] = $catAlbum['Title'];
                }
            }
            $params['q'] = 'albums:"' . implode('|', $albumTitles) . '"';
        }

        if (!empty($album)) {
            $albumUid = $this->getAlbumUidByName($album);
            if (!empty($albumUid)) {
                $params['s'] = $albumUid;
            }
        }

        $result = $this->request(self::PHOTOS_ENDPOINT, $params);
        
        if (is_array($result)) {
            $this->setCached($cacheKey, $result);
        }

        return $result;
    }

    /**
     * Get a single featured photo from a category or album
     * Returns the newest photo from the specified album or category
     */
    public function getFeaturedPhoto(
        string $album = '',
        string $category = ''
    ): ?array {
        try {
            // Use listPhotos which handles both album and category filtering correctly
            $photos = $this->listPhotos(1, $album, $category, 'newest');
            if (is_array($photos) && count($photos) > 0) {
                return $photos[0];
            }
        } catch (\RuntimeException $e) {
            return null;
        }

        return null;
    }

    /**
     * Get the total count of photos matching the search criteria
     * This uses the same filtering logic as listPhotos() but with a large limit
     */
    public function getPhotoCount(
        string $album = '',
        string $category = ''
    ): int {
        // Use a large limit to fetch as many photos as possible
        $limit = 10000;
        $params = ['limit' => $limit, 'order' => 'random', 'count' => $limit];

        $albumUids = [];
        $albumTitles = [];

        // Match the filtering logic from listPhotos()
        if (!empty($category)) {
            $categoryAlbums = $this->getAlbumsByCategory($category, 100);
            foreach ($categoryAlbums as $catAlbum) {
                if (!empty($catAlbum['UID'])) {
                    $albumUids[] = $catAlbum['UID'];
                    $albumTitles[] = $catAlbum['Title'];
                }
            }
            $params['q'] = 'albums:"' . implode('|', $albumTitles) . '"';
        }

        if (!empty($album)) {
            $albumUid = $this->getAlbumUidByName($album);
            if (!empty($albumUid)) {
                $params['s'] = $albumUid;
            }
        }

        // Use the request() method which returns the photo array
        try {
            $photos = $this->request(self::PHOTOS_ENDPOINT, $params);
            if (is_array($photos)) {
                return count($photos);
            }
        } catch (\RuntimeException $e) {
            // Return 0 on error
            return 0;
        }

        return 0;
    }

    public function getAlbumsByCategory(string $category, int $limit = 100): array
    {
        if (empty($category)) {
            return [];
        }

        // Check cache first to avoid expensive PhotoPrism request
        $cacheKey = 'albums_' . md5($category . $limit);
        $cached = $this->getCached($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = ['category' => $category, 'count' => $limit, 'order' => 'newest'];
        $response = $this->request('/albums', $params);
        $result = is_array($response) ? $response : [];

        // Cache the result for 1 hour
        $this->setCached($cacheKey, $result);

        return $result;
    }

    /**
     * Get cached data if valid
     */
    private function getCached(string $key): ?array
    {
        if (!is_dir($this->cacheDir)) {
            return null;
        }

        $file = $this->cacheDir . '/.api_cache_' . $key . '.json';
        if (!file_exists($file)) {
            return null;
        }

        $stat = stat($file);
        if ($stat && (time() - $stat['mtime']) > self::CACHE_TTL) {
            @unlink($file);
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Set cache data
     */
    private function setCached(string $key, array $data, ?int $ttl = null): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $file = $this->cacheDir . '/.api_cache_' . $key . '.json';
        @file_put_contents($file, json_encode($data));
    }

    private function getAlbumUidByName(string $albumName): ?string
    {
        $allAlbums = $this->request('/albums', ['count' => 1000]); // Fetch a large number of albums
        foreach ($allAlbums as $album) {
            if (isset($album['Title']) && $album['Title'] === $albumName && isset($album['UID'])) {
                return (string) $album['UID'];
            }
        }
        return null;
    }

    public function getThumbnailUrl(array $photo, int $size = 224): ?string
    {
        $hash = $this->getPhotoHash($photo);
        $previewToken = $this->getPreviewToken();
        if ($hash !== null && $previewToken !== null) {
            return $this->baseUrl . self::THUMBNAIL_PATH . '/' . rawurlencode($hash) . '/'
                . rawurlencode($previewToken) . '/' . $this->mapPixelSizeToThumbnailName($size);
        }

        return null;
    }

    /**
     * Return the photo identifier from various possible key names.
     */
    private function getPhotoIdFromArray(array $photo): ?string
    {
        $candidates = ['uuid', 'UUID', 'uid', 'UID', 'id', 'ID'];
        foreach ($candidates as $k) {
            if (!empty($photo[$k])) {
                return (string) $photo[$k];
            }
        }
        return null;
    }

    /**
     * Return the SHA1 file hash used by PhotoPrism's /api/v1/t/:hash/:token/:size endpoint.
     */
    public function getPhotoHash(array $photo): ?string
    {
        foreach (['Hash', 'hash'] as $k) {
            if (!empty($photo[$k])) {
                return (string) $photo[$k];
            }
        }
        return null;
    }

    /**
     * Map a requested pixel size to a PhotoPrism thumbnail size name.
     */
    private function mapPixelSizeToThumbnailName(int $size): string
    {
        if ($size <= 50) {
            return 'tile_50';
        }
        if ($size <= 100) {
            return 'tile_100';
        }
        if ($size <= 224) {
            return 'tile_224';
        }
        if ($size <= 384) {
            return 'tile_384';
        }
        if ($size <= 480) {
            return 'tile_480';
        }
        if ($size <= 500) {
            return 'tile_500';
        }
        // For medium size, use tile_500 (not full-size)
        if ($size <= 800) {
            return 'tile_500';
        }
        // For anything larger, use fit_5120 (full resolution)
        if ($size > 800) {
            return 'fit_5120';
        }

        return 'tile_500';
    }

    /**
     * Return the preview token from the most recent PhotoPrism search response.
     */
    private function getPreviewToken(): ?string
    {
        if ($this->previewToken !== null && $this->previewToken !== '') {
            return $this->previewToken;
        }

        $last = $this->getLastRequestDebug();
        if (!empty($last['preview_token'])) {
            return (string) $last['preview_token'];
        }

        if (!empty($last['curl_verbose']) && is_string($last['curl_verbose'])) {
            if (preg_match('/^[< ]+x-preview-token:\s*(\S+)/im', $last['curl_verbose'], $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * Fetch the thumbnail binary for a photo and return a data URI.
     * Returns null on failure.
     */
    public function fetchThumbnailDataUri(array $photo, int $size = 200): ?string
    {
        $thumbUrl = $this->getThumbnailUrl($photo, $size);
        if ($thumbUrl === null) {
            return null;
        }

        $headers = $this->getThumbnailHeaders();

        $ch = $this->initCurl($thumbUrl, $headers, self::THUMBNAIL_REQUEST_TIMEOUT);
        [$data, $info, $error] = $this->executeCurl($ch);

        if ($data === false || ($info['http_code'] ?? 0) >= 400) {
            return null;
        }

        // PhotoPrism returns a placeholder SVG when the hash/token/size is invalid.
        if (str_starts_with($data, '<svg') || str_starts_with($data, '<?xml')) {
            return null;
        }

        $contentType = $info['content_type'] ?? 'image/jpeg';
        if (str_contains($contentType, ';')) {
            $contentType = trim(explode(';', $contentType, 2)[0]);
        }

        return 'data:' . $contentType . ';base64,' . base64_encode($data);
    }


    /**
     * Fetch multiple thumbnails in parallel and return them as data URIs.
     * Returns an array mapping original photo hash to data URI.
     */
    public function fetchThumbnailsDataUriParallel(array $photos, int $size = 200): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];
        $photoHashes = [];

        foreach ($photos as $photo) {
            $hash = $this->getPhotoHash($photo);
            if ($hash === null) {
                continue;
            }

            $thumbUrl = $this->getThumbnailUrl($photo, $size);
            if ($thumbUrl === null) {
                continue;
            }

            $ch = $this->initCurl($thumbUrl, $this->getThumbnailHeaders(), self::THUMBNAIL_REQUEST_TIMEOUT);
            // For multi handles we need to disable RETURNTRANSFER at init time; it's already set by initCurl
            curl_multi_add_handle($multiHandle, $ch);

            $handles[$thumbUrl] = array ($ch, $photo);
            $photoHashes[$thumbUrl] = $hash;
        }

        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $thumbUrl => $handle) {
            $ch = $handle[0];
            $photo = $handle[1];
            $data = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);

            if ($data === false || ($info['http_code'] ?? 0) >= 400) {
                continue;
            }

            if (str_starts_with($data, '<svg') || str_starts_with($data, '<?xml')) {
                continue;
            }

            $contentType = $info['content_type'] ?? 'image/jpeg';
            if (str_contains($contentType, ';')) {
                $contentType = trim(explode(';', $contentType, 2)[0]);
            }
            $results[$photoHashes[$thumbUrl]] = 'data:' . $contentType . ';base64,' . base64_encode($data);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Fetch multiple photo details in parallel using curl_multi and return a map of id => details.
     * Missing or failed entries will have null values.
     *
     * @param string[] $ids
     * @return array<string, array|null>
     */
    public function fetchPhotosDetailsParallel(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($ids as $id) {
            $url = $this->baseUrl . self::API_VERSION_PATH . '/photos/' . rawurlencode((string) $id);
            $ch = $this->initCurl($url, $this->buildHeaders(false));
            curl_multi_add_handle($multiHandle, $ch);
            $handles[(string) $id] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $id => $ch) {
            $data = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);

            if ($data === false || (($info['http_code'] ?? 0) >= 400)) {
                $results[$id] = null;
                continue;
            }

            $decoded = json_decode($data, true);
            $results[$id] = is_array($decoded) ? $decoded : null;
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Return album titles for a photo. Always returns an array (possibly empty).
     *
     * @param array $photo
     * @return string[]
     */
    public function getPhotoAlbums(array $photo): array
    {
        // If albums are already embedded in the photo payload, extract titles directly.
        if (!empty($photo['Albums']) && is_array($photo['Albums'])) {
            return $this->extractAlbumTitles($photo['Albums']);
        }

        if (!empty($photo['albums']) && is_array($photo['albums'])) {
            return $this->extractAlbumTitles($photo['albums']);
        }

        $photoId = $this->getPhotoIdFromArray($photo);
        if ($photoId === null) {
            return [];
        }

        try {
            $photoDetails = $this->request('/photos/' . rawurlencode($photoId));
            if (!empty($photoDetails['Albums']) && is_array($photoDetails['Albums'])) {
                return $this->extractAlbumTitles($photoDetails['Albums']);
            }
        } catch (\RuntimeException $e) {
            // Return empty list on error to keep calling code simple.
            return [];
        }

        return [];
    }

    /**
     * Normalize an array of album entries to an array of titles.
     * Accepts arrays of album objects or string UIDs; falls back to UID when title missing.
     *
     * @param array $albums
     * @return string[]
     */
    private function extractAlbumTitles(array $albums): array
    {
        $titles = [];
        foreach ($albums as $a) {
            if (is_string($a)) {
                $titles[] = $a;
                continue;
            }
            if (!is_array($a)) {
                continue;
            }
            if (!empty($a['Title'])) {
                $titles[] = (string) $a['Title'];
                continue;
            }
            if (!empty($a['title'])) {
                $titles[] = (string) $a['title'];
                continue;
            }
            if (!empty($a['Name'])) {
                $titles[] = (string) $a['Name'];
                continue;
            }
            if (!empty($a['name'])) {
                $titles[] = (string) $a['name'];
                continue;
            }
            if (!empty($a['UID'])) {
                $titles[] = (string) $a['UID'];
                continue;
            }
        }
        return array_values(array_unique(array_filter($titles, fn($v) => $v !== null && $v !== '')));
    }

    private function request(
        string $path,
        array $params = [],
        string $method = 'GET',
        ?array $body = null,
        bool $skipAuth = false
    ): array {
        $url = $this->baseUrl . self::API_VERSION_PATH . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = $this->buildHeaders($skipAuth);
        $this->lastRequestDebug = [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'params' => $params,
            'body' => $body,
        ];
        // Persist last request debug so it survives separate PHP requests
        $tmp = sys_get_temp_dir() . '/image_mosaic_last_request.json';
        @file_put_contents($tmp, json_encode($this->lastRequestDebug, JSON_UNESCAPED_SLASHES));

        $responseHeaders = [];
        $ch = $this->initCurl($url, $headers);
        curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($headerLine);
            }
        );

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                $payload = json_encode($body);
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
        }

        [$response, $info, $error] = $this->executeCurl($ch);

        if (!empty($responseHeaders['x-preview-token'])) {
            $this->previewToken = $responseHeaders['x-preview-token'];
        }

        $this->lastRequestDebug['raw_response'] = $response;
        $this->lastRequestDebug['response_info'] = $info;
        $this->lastRequestDebug['response_headers'] = $responseHeaders;
        $this->lastRequestDebug['preview_token'] = $this->previewToken;
        $this->lastRequestDebug['http_status'] = $info['http_code'] ?? null;
        // Update persisted debug file with response details
        $tmp = sys_get_temp_dir() . '/image_mosaic_last_request.json';
        @file_put_contents($tmp, json_encode($this->lastRequestDebug, JSON_UNESCAPED_SLASHES));

        if ($response === false) {
            throw new \RuntimeException('PhotoPrism API request failed: ' . $error);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (($info['http_code'] ?? 0) >= 400) {
                throw new \RuntimeException(
                    'PhotoPrism API request failed (' . ($info['http_code'] ?? 'unknown') . '): ' . $response
                );
            }
            throw new \RuntimeException('PhotoPrism API returned invalid JSON: ' . json_last_error_msg());
        }

        if (($info['http_code'] ?? 0) >= 400) {
            $message = $data['error'] ?? $data['message'] ?? json_encode($data, JSON_UNESCAPED_SLASHES);
            throw new \RuntimeException(
                'PhotoPrism API request failed (' . ($info['http_code'] ?? 'unknown') . '): ' . $message
            );
        }

        return is_array($data) ? $data : [];
    }

    public function getLastRequestDebug(): array
    {
        if (!empty($this->lastRequestDebug)) {
            return $this->lastRequestDebug;
        }

        $tmp = sys_get_temp_dir() . '/image_mosaic_last_request.json';
        if (is_readable($tmp)) {
            $contents = @file_get_contents($tmp);
            if ($contents !== false) {
                $decoded = json_decode($contents, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * Build headers for thumbnail requests.
     */
    private function getThumbnailHeaders(): array
    {
        $headers = $this->buildHeaders(false);
        $filtered = [];
        foreach ($headers as $h) {
            if (stripos($h, 'Accept:') === 0 || stripos($h, 'Content-Type:') === 0) {
                continue;
            }
            $filtered[] = $h;
        }
        $filtered[] = 'Accept: image/*';
        return $filtered;
    }

    private function buildHeaders(bool $skipAuth = false): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($skipAuth) {
            return $headers;
        }

        if ($this->accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
            $headers[] = 'X-Auth-Token: ' . $this->accessToken;
        }

        return $headers;
    }

    /**
     * Initialize a cURL handle with common options.
     *
     * @param string $url
     * @param array $headers
     * @param int|null $timeout
     * @return CurlHandle
     */
    private function initCurl(string $url, array $headers, ?int $timeout = null): CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout ?? self::DEFAULT_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::DEFAULT_CONNECT_TIMEOUT);
        return $ch;
    }

    /**
     * Execute a configured cURL handle and return response, info and error.
     *
     * @param CurlHandle $ch
     * @return array [response:string, info:array, error:string]
     */
    private function executeCurl(CurlHandle $ch): array
    {
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return [$response, $info, $error];
    }
}
