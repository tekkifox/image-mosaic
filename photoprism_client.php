<?php

namespace ImageMosaic;

class PhotoPrismClient
{
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

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['photo_prism_base_url'] ?? '', '/');
        $this->apiKey = $config['photo_prism_api_key'] ?? '';
        $this->accessToken = $config['photo_prism_access_token'] ?? '';
        $this->useBasicAuth = $config['photo_prism_use_basic_auth'] ?? false;
        $this->oauthClientId = $config['photo_prism_oauth_client_id'] ?? '';
        $this->oauthClientSecret = $config['photo_prism_oauth_client_secret'] ?? '';
        $this->username = $config['photo_prism_username'] ?? '';
        $this->password = $config['photo_prism_password'] ?? '';
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
            return 'basic_auth';
        }

        // Prefer a configured access token over API key, basic auth, or password grant.
        if ($this->accessToken !== '') {
            return 'access_token';
        }

        if ($this->apiKey !== '') {
            return 'api_key';
        }

        if ($this->username !== '' && $this->password !== '') {
            return 'oauth_password';
        }

        return 'none';
    }

    public function listPhotos(int $limit = 144): array
    {
        return $this->request('/photos', ['limit' => $limit, 'order' => 'random', 'count' => $limit]);
    }

    public function getThumbnailUrl(array $photo, int $size = 224): ?string
    {
        $hash = $this->getPhotoHashFromArray($photo);
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
    private function getPhotoHashFromArray(array $photo): ?string
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

        $headers = $this->buildHeaders(false);
        $filtered = [];
        foreach ($headers as $h) {
            if (stripos($h, 'Accept:') === 0 || stripos($h, 'Content-Type:') === 0) {
                continue;
            }
            $filtered[] = $h;
        }
        $filtered[] = 'Accept: image/*';

        $ch = curl_init($thumbUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $filtered);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

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

    private function request(
        string $path,
        array $params = [],
        string $method = 'GET',
        ?array $body = null,
        bool $skipAuth = false
    ): array {
        if (!$skipAuth && $this->getAuthType() === 'oauth_password') {
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
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
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

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);

        curl_close($ch);

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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        $error = curl_error($ch);
        curl_close($ch);

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
}
