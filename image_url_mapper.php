<?php

declare(strict_types=1);

namespace ImageMosaic;

/**
 * Image URL Mapper - Maps image hashes to PhotoPrism URLs
 * 
 * This class keeps PhotoPrism URLs hidden from the frontend.
 * The frontend only knows about image hashes, not actual URLs.
 * 
 * Benefits:
 * - PhotoPrism URLs never exposed to client
 * - Prevents direct access to PhotoPrism API
 * - Allows URL changes without frontend updates
 * - Provides security through obscurity
 */
class ImageUrlMapper
{
    private array $urlCache = [];
    private const CACHE_FILE = 'public/cache/.url_mapping.json';

    /**
     * Generate a hash for an image URL
     * 
     * @param string $url PhotoPrism image URL
     * @return string SHA256 hash of URL
     */
    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * Get the image hash from the URL
     * 
     * @param string $url PhotoPrism image URL
     * @return string Short hash suitable for JSON
     */
    public static function getImageHash(string $url): string
    {
        $full_hash = self::hashUrl($url);
        return substr($full_hash, 0, 16); // First 16 chars of SHA256
    }

    /**
     * Store URL mapping for later retrieval
     * 
     * @param string $url PhotoPrism image URL
     * @param string|null $hash Optional custom hash
     * @return string The hash generated
     */
    public function mapUrl(string $url, ?string $hash = null): string
    {
        $hash = $hash ?? self::getImageHash($url);
        $this->urlCache[$hash] = $url;
        return $hash;
    }

    /**
     * Get URL from hash
     * 
     * @param string $hash Image hash
     * @return string|null PhotoPrism URL or null if not found
     */
    public function getUrlFromHash(string $hash): ?string
    {
        return $this->urlCache[$hash] ?? null;
    }

    /**
     * Check if hash is mapped
     * 
     * @param string $hash Image hash
     * @return bool True if hash exists in mapping
     */
    public function hasHash(string $hash): bool
    {
        return isset($this->urlCache[$hash]);
    }

    /**
     * Get all mapped hashes
     * 
     * @return array Array of hash => url
     */
    public function getAllMappings(): array
    {
        return $this->urlCache;
    }

    /**
     * Clear all mappings
     */
    public function clearMappings(): void
    {
        $this->urlCache = [];
    }

    /**
     * Persist mappings to file (session-based, cleared on server restart)
     * 
     * @return bool True if saved successfully
     */
    public function persistMappings(): bool
    {
        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($this->urlCache, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return file_put_contents(self::CACHE_FILE, $json) !== false;
    }

    /**
     * Load mappings from file
     * 
     * @return bool True if loaded successfully
     */
    public function loadMappings(): bool
    {
        if (!file_exists(self::CACHE_FILE)) {
            return false;
        }

        $json = file_get_contents(self::CACHE_FILE);
        if ($json === false) {
            return false;
        }

        $data = json_decode($json, true);
        if (is_array($data)) {
            $this->urlCache = $data;
            return true;
        }

        return false;
    }

    /**
     * Get the number of mapped URLs
     * 
     * @return int Number of mappings
     */
    public function count(): int
    {
        return count($this->urlCache);
    }
}
