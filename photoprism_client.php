<?php

declare(strict_types=1);

namespace ImageMosaic;

use RuntimeException;
use InvalidArgumentException;

class PhotoPrismClient
{
    // --- API Endpoints and Configuration Keys ---
    private const API_VERSION_PATH = '/api/v1';
    private const PHOTOS_ENDPOINT = '/photos';
    private const OAUTH_TOKEN_ENDPOINT = '/oauth/token';
    private const SESSION_ENDPOINT = '/session';
    private const THUMBNAIL_PATH = '/api/v1/t';

    // --- Auth Type Constants ---
    private const AUTH_TYPE_BASIC = 'basic_auth';
    private const AUTH_TYPE_ACCESS_TOKEN = 'access_token';
    private const AUTH_TYPE_API_KEY = 'api_key';
    private const AUTH_TYPE_OAUTH_PASSWORD = 'oauth_password';
    private const AUTH_TYPE_NONE = 'none';

    private const CONFIG_BASE_URL = 'photo_prism_base_url';
    private const CONFIG_API_KEY = 'photo_prism_api_key';
    private const CONFIG_ACCESS_TOKEN = 'photo_prism_access_token';
    private const CONFIG_USE_BASIC_AUTH = 'photo_prism_use_basic_auth';
    private const CONFIG_OAUTH_CLIENT_ID = 'photo_prism_oauth_client_id';
    private const CONFIG_OAUTH_CLIENT_SECRET = 'photo_prism_oauth_client_secret';
    private const CONFIG_USERNAME = 'photo_prism_username';
    private const CONFIG_PASSWORD = 'photo_prism_password';

    // --- Default cURL Options ---
    private const DEFAULT_TIMEOUT = 15;
    private const DEFAULT_CONNECT_TIMEOUT = 10;
    private const THUMBNAIL_REQUEST_TIMEOUT = 15; // Specific timeout for thumbnail fetches

    // --- Class Properties ---
    private string $baseUrl;
    private string $apiKey;
    private string $accessToken;
    private bool $useBasicAuth;
    private string $oauthClientId;
    private string $oauthClientSecret;
    private string $username;
    private string $password;
    private ?int $tokenExpiresAt = null;
    private ?string $previewToken = null;
    private array $lastRequestDebug = [];

    /**
     * Constructor initializes the client with configuration array.
     */
    public function __construct(array $config)
    {
        // Use throw exceptions for missing critical config values instead of assigning empty strings,
        // as this enforces configuration correctness early.
        $this->baseUrl = rtrim($config['photo_prism_base_url'] ?? '', '/');
        $this->apiKey = $config['photo_prism_api_key'] ?? '';
        $this->accessToken = $config['photo_prism_access_token'] ?? '';
        $this->useBasicAuth = $config['photo_prism_use_basic_auth'] ?? false;
        $this->oauthClientId = $config['photo_prism_oauth_client_id'] ?? '';
        $this->oauthClientSecret = $config['photo_prism_oauth_client_secret'] ?? '';
        $this->username = $config['photo_prism_username'] ?? '';
        $this->password = $config['photo_prism_password'] ?? '';

        if (empty($this->baseUrl)) {
            throw new InvalidArgumentException('Base URL must be configured.');
        }
    }

    public function getConnectionDebug(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'hasApiKey' => $this->apiKey !== '',
            'hasAccessToken' => $this->accessToken !== '',
            'hasBasicAuth' => $this->useBasicAuth && $this->username !== '' && $this->password !== '',
            'authType' => $this->getAuthType(),
            'timeout' => 15,
            'connectTimeout' => 10,
        ];
    }

    private function getAuthType(): string
    {
        if ($this->useBasicAuth && $this->username !== '' && $this->password !== '') {
            return self::AUTH_TYPE_BASIC;
        }

        // Prefer a configured access token over API key, basic auth, or password grant.
        if ($this->accessToken !== '') {
            return self::AUTH_TYPE_ACCESS_TOKEN;
        }

        if ($this->apiKey !== '') {
            return self::AUTH_TYPE_API_KEY;
        }

        if ($this->username !== '' && $this->password !== '') {
            return self::AUTH_TYPE_OAUTH_PASSWORD;
        }

        return self::AUTH_TYPE_NONE;
    }

    public function listPhotos(
        int $limit = 144,
        string $album = '',
        string $category = '',
        string $order = 'random'
    ): array {
        $params = ['limit' => $limit, 'order' => $order, 'count' => $limit];

        $albumUids = [];

        if (!empty($category)) {
            $categoryAlbums = $this->getAlbumsByCategory($category, 100); // Fetch up to 100 albums in category
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

        return $this->request('/photos', $params);
    }

    public function getAlbumsByCategory(string $category, int $limit = 100): array
    {
        if (empty($category)) {
            return [];
        }

        $params = ['category' => $category, 'count' => $limit, 'order' => 'newest'];

        $response = $this->request('/albums', $params);

        return is_array($response) ? $response : [];
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
            return $this->baseUrl . '/api/v1/t/' . rawurlencode($hash) . '/'
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
        if ($size > 500) {
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

    public function getPhotoPageUrl(array $photo): string
    {
        // Accept various key casings for the photo identifier.
        $candidates = ['uuid', 'UUID', 'uid', 'UID', 'id', 'ID'];
        foreach ($candidates as $k) {
            if (!empty($photo[$k])) {
                return $this->getThumbnailUrl($photo, 5120);
            }
        }
        return $this->baseUrl;
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
        if (!$skipAuth && $this->getAuthType() === self::AUTH_TYPE_OAUTH_PASSWORD) {
            $this->refreshAccessToken();
        }

        $url = $this->baseUrl . '/api/v1' . $path;
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

    private function refreshAccessToken(): void
    {

        if ($this->accessToken !== '' && $this->tokenExpiresAt !== null && time() + 30 < $this->tokenExpiresAt) {
            return;
        }

        if ($this->username === '' || $this->password === '') {
            throw new \RuntimeException('PhotoPrism OAuth password grant requires username and password.');
        }

        // Try OAuth password grant with client authentication via HTTP Basic Auth
        try {
            $response = $this->requestOAuthToken();
            $token = $this->extractAccessToken($response);
            if ($token !== null) {
                $this->accessToken = $token;
                if (!empty($response['expires_in'])) {
                    $this->tokenExpiresAt = time() + (int) $response['expires_in'];
                }
                return;
            }
        } catch (\RuntimeException $e) {
            // OAuth token endpoint failed; will attempt session login below
        }

        // Fallback to session login if OAuth fails
        try {
            $this->sessionLogin();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'PhotoPrism authentication failed: OAuth token request and session login both unsuccessful.'
            );
        }
    }

    /**
     * Request OAuth token from PhotoPrism using password grant with client credentials via HTTP Basic Auth.
     */
    private function requestOAuthToken(): array
    {
        if ($this->username === '' || $this->password === '') {
            throw new \RuntimeException('OAuth token request requires username and password.');
        }

        $url = $this->baseUrl . '/api/v1/oauth/token';

        // Prepare form data for OAuth token endpoint
        $body = http_build_query([
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
        ]);

        // Build headers with client authentication via HTTP Basic Auth
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];

        // Add HTTP Basic Auth header with client credentials if available
        if ($this->oauthClientId !== '' && $this->oauthClientSecret !== '') {
            $clientAuth = base64_encode($this->oauthClientId . ':' . $this->oauthClientSecret);
            $headers[] = 'Authorization: Basic ' . $clientAuth;
        }

        $ch = $this->initCurl($url, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        [$response, $info, $error] = $this->executeCurl($ch);

        if ($response === false) {
            throw new \RuntimeException('OAuth token request failed: ' . $error);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('OAuth token endpoint returned invalid JSON: ' . json_last_error_msg());
        }

        if (($info['http_code'] ?? 0) >= 400) {
            $message = $data['error'] ?? $data['message'] ?? json_encode($data, JSON_UNESCAPED_SLASHES);
            throw new \RuntimeException(
                'OAuth token request failed (' . ($info['http_code'] ?? 'unknown') . '): ' . $message
            );
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Attempt PhotoPrism session login (POST /api/v1/session) using username/password.
     * If successful, sets `$this->accessToken` from response fields.
     * Throws \RuntimeException on failure.
     */
    private function sessionLogin(): void
    {
        if ($this->username === '' || $this->password === '') {
            throw new \RuntimeException('Session login requires username and password.');
        }

        $body = [
            'username' => $this->username,
            'password' => $this->password,
        ];

        $response = $this->request('/session', [], 'POST', $body, true);

        $token = $this->extractAccessToken($response);
        if ($token === null) {
            // Some PhotoPrism instances may return token in different fields or via cookie;
            // include full response in error
            $details = json_encode($response, JSON_UNESCAPED_SLASHES);
            throw new \RuntimeException(
                'PhotoPrism session login did not return an access token. Response: ' . $details
            );
        }

        $this->accessToken = $token;
        // Session tokens may not include expires_in; leave tokenExpiresAt null if absent
        if (!empty($response['expires_in'])) {
            $this->tokenExpiresAt = time() + (int) $response['expires_in'];
        }
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

    private function extractAccessToken(array $response): ?string
    {
        if (!empty($response['access_token'])) {
            return $response['access_token'];
        }

        if (!empty($response['token'])) {
            return $response['token'];
        }

        if (!empty($response['accessToken'])) {
            return $response['accessToken'];
        }

        if (!empty($response['id_token'])) {
            return $response['id_token'];
        }

        return null;
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
        } elseif ($this->apiKey !== '') {
            $headers[] = 'X-Api-Key: ' . $this->apiKey;
        } elseif ($this->getAuthType() === 'basic_auth') {
            $credentials = base64_encode($this->username . ':' . $this->password);
            $headers[] = 'Authorization: Basic ' . $credentials;
        }

        return $headers;
    }

    /**
     * Initialize a cURL handle with common options.
     *
     * @param string $url
     * @param array $headers
     * @param int|null $timeout
     * @return resource
     */
    private function initCurl(string $url, array $headers, ?int $timeout = null)
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
     * @param resource $ch
     * @return array [response:string, info:array, error:string]
     */
    private function executeCurl($ch): array
    {
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return [$response, $info, $error];
    }
}
