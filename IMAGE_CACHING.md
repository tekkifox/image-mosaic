# Image Caching System

A local caching system that downloads and stores full-resolution images from PhotoPrism before displaying them in the lightbox popup, improving performance and reducing server load.

## How It Works

### User Flow

1. **Browse gallery** - User sees thumbnails (served via data URIs or cached quickly)
2. **Click image** - Lightbox opens with low-res placeholder
3. **Cache check** - System checks if full image is cached locally
4. **Download** - If not cached, download from PhotoPrism and store locally
5. **Display** - Show cached full-resolution image

### Architecture

```
PhotoPrism Server
       ↓
   (fetch image)
       ↓
  CacheManager
       ↓ (cache miss)
  public/cache/  ← Store locally
       ↓ (cache hit)
   Lightbox Display
```

## Components

### 1. CacheManager Class
**File:** `cache_manager.php`

Core caching logic with methods:
- `get($url, $ttl)` - Get cached content or fetch from URL
- `set($url, $content)` - Store content in cache
- `has($url, $ttl)` - Check if URL is cached
- `delete($url)` - Remove cached file
- `cleanup($ttl)` - Remove expired files
- `flush()` - Clear all cache
- `getStats()` - Get cache statistics
- `getMetadata($url)` - Get cache file info

**Features:**
- Atomic writes (temp file → rename)
- File locking (prevent duplicate downloads)
- TTL-based expiration (default 30 days)
- Automatic directory creation
- SHA256 hashing for URL → filename mapping
- Preserves file extensions

### 2. API Cache Endpoint
**File:** `api.php` - `/api.php?action=cache`

RESTful endpoints for caching:

#### GET: `?action=cache&subaction=get&url=<URL>`
Fetch and cache image from PhotoPrism
- Returns binary image data
- Sets proper cache headers
- TTL: 30 days

**Example:**
```bash
curl "http://localhost/api.php?action=cache&subaction=get&url=https://photoprism.example.com/api/v1/t/abc123/e5ic6ysq/tile_500"
```

#### INFO: `?action=cache&subaction=info&url=<URL>`
Get cache metadata for a URL

**Response:**
```json
{
  "cached": true,
  "metadata": {
    "path": "public/cache/v1_abc123.jpg",
    "size": 45678,
    "mtime": 1719345600,
    "created": "2026-06-25 22:30:00",
    "age_seconds": 3600,
    "url_hash": "v1_abc123.jpg"
  }
}
```

#### STATS: `?action=cache&subaction=stats`
Get cache statistics

**Response:**
```json
{
  "stats": {
    "cache_dir": "public/cache",
    "file_count": 42,
    "total_size": 5242880,
    "total_size_mb": 5.0,
    "oldest_file": "v1_abc123.jpg",
    "newest_file": "v1_def456.jpg",
    "default_ttl_days": 30
  }
}
```

#### CLEANUP: `?action=cache&subaction=cleanup`
Remove expired cache files (server-side)

**Response:**
```json
{
  "removed": 5
}
```

#### FLUSH: `?action=cache&subaction=flush`
Clear all cache (use with caution!)

**Response:**
```json
{
  "removed": 42
}
```

### 3. React Lightbox Component
**File:** `src/components/Lightbox.jsx`

Updated to:
- Detect PhotoPrism URLs (full images)
- Request cached versions via API
- Show loading indicator while caching
- Fallback to original URL if caching fails
- Display cached image with cache headers

**Key changes:**
```jsx
// When image URL changes, cache it
useEffect(() => {
  // For PhotoPrism URLs, use caching endpoint
  const cacheUrl = `/api.php?action=cache&subaction=get&url=${encodeURIComponent(imageUrl)}`;
  
  // Preload and trigger caching
  const img = new Image();
  img.onload = () => setDisplayImage(cacheUrl);
  img.src = cacheUrl;
}, [currentIndex, items]);
```

### 4. CLI Cache Manager
**File:** `scripts/cache-manager.php`

Command-line tool for cache management:

```bash
# View cache statistics
php scripts/cache-manager.php stats

# Clean expired files (> 30 days)
php scripts/cache-manager.php cleanup

# Clean older than 7 days
php scripts/cache-manager.php cleanup --ttl 7

# List cached files
php scripts/cache-manager.php list --limit 10

# Clear all cache
php scripts/cache-manager.php flush

# Show help
php scripts/cache-manager.php help
```

## Directory Structure

```
project-root/
├── cache_manager.php              # Core caching class
├── api.php                        # Cache API endpoints
├── scripts/
│   └── cache-manager.php          # CLI management tool
├── public/
│   └── cache/                     # Cache storage directory
│       ├── v1_abc123def456.jpg   # Cached images
│       ├── v1_def456ghi789.jpg
│       └── .locks/                # Lock files (prevent race conditions)
└── src/
    └── components/
        └── Lightbox.jsx           # Updated with caching
```

## Configuration

### Cache Directory
Default: `public/cache/`

To change, modify CacheManager constructor:
```php
$cache = new CacheManager('/var/cache/image-mosaic');
```

### TTL (Time To Live)
Default: 30 days

To change, modify in api.php:
```php
$content = $cache->get($photoUrl, 7 * 24 * 60 * 60); // 7 days
```

Or in cleanup script:
```bash
php scripts/cache-manager.php cleanup --ttl 14  # Remove files > 14 days
```

### Cache Size Limits
Currently unlimited. To implement size limits:
```php
if ($stats['total_size_mb'] > 1000) {
    // Remove oldest files
}
```

## Performance

### Storage Requirements
- **Per image**: 20-100 KB (depends on resolution)
- **For 100 images**: ~2-10 MB
- **For 1000 images**: ~20-100 MB

### Cache Hit Rate
- First view of image: **0% (download)**
- Subsequent views: **100% (local load)**
- New images only: Periodic background caching

### Load Time Improvements
- **Without cache**: 500-2000 ms (network latency)
- **With cache**: 50-200 ms (local disk read)
- **Improvement**: 5-20x faster

## API Usage Examples

### JavaScript (Frontend)

```javascript
// Cache an image
const imageUrl = 'https://photoprism.example.com/api/v1/t/abc123/...';
const cachedUrl = `/api.php?action=cache&subaction=get&url=${encodeURIComponent(imageUrl)}`;

const img = new Image();
img.onload = () => {
  // Image is now cached, display from cache
  document.querySelector('.lightbox-image').src = cachedUrl;
};
img.src = cachedUrl;
```

### PHP (Backend)

```php
$cache = new \ImageMosaic\CacheManager('public/cache');

// Get cached image
$content = $cache->get($photoUrl);
echo $content;

// Check if cached
if ($cache->has($imageUrl)) {
  echo "Image is cached";
}

// Get metadata
$metadata = $cache->getMetadata($imageUrl);
echo "Image size: " . $metadata['size'] . " bytes";
echo "Created: " . $metadata['created'];
```

### cURL (Testing)

```bash
# Test caching endpoint
curl -i "http://localhost/api.php?action=cache&subaction=get&url=https://example.com/image.jpg"

# Get cache stats
curl "http://localhost/api.php?action=cache&subaction=stats" | jq

# Clean cache via API
curl "http://localhost/api.php?action=cache&subaction=cleanup"
```

## File Naming

Images are cached with deterministic filenames:
```
v1_<SHA256(url)>.<original_extension>
```

**Example:**
- URL: `https://photoprism.example.com/api/v1/t/abc123xyz/tile_500`
- SHA256: `3f5a8c2b1e9d4f7a6c8b3d2e1f0a9c8b7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c`
- Filename: `v1_3f5a8c2b1e9d4f7a6c8b3d2e1f0a9c8b7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c.jpg`

**Benefits:**
- Unique per URL (no collisions)
- Deterministic (same URL = same filename)
- Version prefix (`v1_`) for cache invalidation
- Preserves file extension for proper MIME type detection

## Cache Invalidation

### Manual Invalidation
```bash
# Remove one image from cache
php scripts/cache-manager.php delete <hash>

# Remove all expired files
php scripts/cache-manager.php cleanup

# Clear entire cache
php scripts/cache-manager.php flush
```

### Automatic Expiration
Files older than TTL (default 30 days) are considered stale. They'll be:
- Removed on next `cleanup` run
- Re-downloaded on next view (automatic refresh)

### Version-based Invalidation
Change version prefix in `CacheManager`:
```php
private const CACHE_VERSION = '2';  // Changes all filenames, effective flush
```

## Troubleshooting

### Cache not working
1. Check directory permissions:
   ```bash
   ls -la public/cache/
   chmod 755 public/cache
   chmod 644 public/cache/*
   ```

2. Check API endpoint:
   ```bash
   curl "http://localhost/api.php?action=cache&subaction=stats"
   ```

3. Enable debug mode:
   ```bash
   curl "http://localhost/api.php?action=cache&subaction=stats&debug=1"
   ```

### Images not caching
1. Check cache directory exists and is writable
2. Verify PhotoPrism URL is reachable
3. Check image is served with correct Content-Type header
4. Check file permissions on downloaded files

### Cache growing too large
1. Check cache stats:
   ```bash
   php scripts/cache-manager.php stats
   ```

2. Clean files older than N days:
   ```bash
   php scripts/cache-manager.php cleanup --ttl 14
   ```

3. Set up cron job for automated cleanup:
   ```bash
   # Daily cleanup (keep last 30 days)
   0 2 * * * php /path/to/scripts/cache-manager.php cleanup
   ```

### Disk space issues
1. Monitor cache size regularly:
   ```bash
   du -sh public/cache/
   ```

2. Implement size-based cleanup (future feature)

3. Use external cache (Redis, Memcached) for very large galleries

## Security Considerations

### URL Validation
Currently accepts any URL. In production, validate:
```php
if (parse_url($url, PHP_URL_HOST) !== 'photoprism.example.com') {
  throw new InvalidArgumentException('Invalid source host');
}
```

### Disk Space DoS
Rate limit cache endpoint:
```php
// Only cache up to 1GB
if ($cache->getStats()['total_size'] > 1024 * 1024 * 1024) {
  http_response_code(429); // Too Many Requests
  exit;
}
```

### File Permissions
Cache directory should not be web-accessible directly:
```apache
<FilesMatch "cache/">
  Deny from all
</FilesMatch>
```

Images are served through PHP API only.

## Future Improvements

1. **Redis/Memcached Support** - In-memory caching for faster access
2. **WebP Conversion** - Compress cached images to WebP format
3. **Thumbnail Generation** - Pre-generate multiple resolutions
4. **LRU Eviction** - Automatically remove least-recently-used files
5. **S3 Backend** - Store cache in AWS S3 or similar
6. **Cache Statistics API** - Track hit rates and performance
7. **Conditional GET** - Use ETags and Last-Modified headers
8. **Partial Downloads** - Resume interrupted downloads

## Integration Checklist

- [x] CacheManager class implemented
- [x] API endpoints created
- [x] React Lightbox updated
- [x] CLI management tool
- [x] Documentation completed
- [ ] Setup cron job for cleanup
- [ ] Configure directory permissions
- [ ] Monitor cache growth
- [ ] Test with large galleries (1000+ images)
- [ ] Enable compression in .htaccess

## Getting Started

### 1. Create cache directory
```bash
mkdir -p public/cache
chmod 755 public/cache
```

### 2. Test caching
```bash
# Get image and cache it
curl -i "http://localhost/api.php?action=cache&subaction=get&url=https://photoprism.example.com/api/v1/t/abc123/tile_500"

# Check stats
php scripts/cache-manager.php stats
```

### 3. Setup cleanup (optional)
```bash
# Add to crontab for daily cleanup
0 2 * * * cd /path/to/project && php scripts/cache-manager.php cleanup

# Or cleanup manually
php scripts/cache-manager.php cleanup
```

### 4. Monitor cache
```bash
# Weekly cache check
php scripts/cache-manager.php stats | mail -s "Cache Report" admin@example.com
```

The caching system is now active! Full images will be cached automatically when viewed in the lightbox.
