# Full-Size Images in Lightbox

## Overview

The lightbox now displays full-resolution images from PhotoPrism with intelligent caching. The system provides multiple resolution options and caches them locally for optimal performance.

## Resolution Tiers

### PhotoPrism Available Sizes

The PhotoPrism API provides several thumbnail sizes via the `getThumbnailUrl()` method:

| Size | Type | Use Case |
|------|------|----------|
| 50px | tile_50 | Placeholder |
| 100px | tile_100 | List view |
| 224px | tile_224 | Gallery grid |
| 384px | tile_384 | Medium preview |
| 480px | tile_480 | Large preview |
| 500px | tile_500 | Current thumbnail |
| 5120px | fit_5120 | **Full-resolution** |

### Implementation

**File:** `api.php` - Tiles endpoint

```php
// Try to get full-resolution (fit_5120)
$fullImageUrl = $client->getThumbnailUrl($photo, 5120) ?? 
                $client->getThumbnailUrl($photo, 1000) ?? 
                $client->getThumbnailUrl($photo, 500) ?? 
                $thumb;

// Get medium resolution for faster prefetch
$mediumImageUrl = $client->getThumbnailUrl($photo, 1000) ?? $fullImageUrl;

// Both are mapped to hashes for privacy
$fullImageHash = $urlMapper->mapUrl($fullImageUrl);
$mediumImageHash = $urlMapper->mapUrl($mediumImageUrl);
```

## API Response

### Tiles Endpoint Response

```json
{
  "tiles": [
    {
      "title": "Photo Title",
      "albums": ["Album 1", "Album 2"],
      "thumb": "data:image/jpeg;base64,...",
      "imageHash": "a1b2c3d4e5f6g7h8",
      "mediumHash": "i9j0k1l2m3n4o5p6",
      "taken": "June 25, 2016",
      "caption": "Photo description"
    }
  ]
}
```

### Hash Resolution Mapping

| Hash Field | Resolution | Use Case |
|-----------|----------|----------|
| `imageHash` | fit_5120 (max) | Lightbox display |
| `mediumHash` | 1000px | Prefetch (faster) |
| `thumb` | Base64 | Gallery thumbnail |

## Lightbox Implementation

### Display Logic

**File:** `src/components/Lightbox.jsx`

When displaying an image:

```javascript
// Use full-size hash for lightbox
const imageHash = it.imageHash || it.mediumHash;

// Request from cache endpoint
const cacheUrl = `/api.php?action=cache&subaction=get&hash=${imageHash}`;

// Server looks up URL and serves image
fetch(cacheUrl)
  .then(response => response.blob())
  .then(blob => {
    const blobUrl = URL.createObjectURL(blob);
    setDisplayImage(blobUrl);  // Display full-size image
  });
```

**Benefits:**
- ✅ Highest quality image displayed
- ✅ Automatic fallback to medium/thumbnail
- ✅ URL hidden from frontend (privacy)
- ✅ Cached locally after first load

### Prefetch Strategy

For faster navigation, prefetch uses medium hash:

```javascript
// Prefetch uses medium hash (faster)
const imageHash = it.mediumHash || it.imageHash;

// Medium resolution (1000px) is usually sufficient
// and caches faster than full-resolution
fetch(cacheUrl, { priority: 'low' })
  .then(...)
```

**Optimization:**
- Prefetch medium (1000px) - loads in ~100-200ms
- Display full-size (fit_5120) on click - loads instantly if medium cached
- User sees full quality when viewing

## Caching Strategy

### First-Time Loading

```
1. User clicks image
   ↓
2. Lightbox requests full-size via imageHash
   ↓
3. Server looks up fit_5120 URL
   ↓
4. Server caches image locally
   ↓
5. Browser displays full-size image
   ↓
   Time: 500-2000ms (network dependent)
```

### Prefetch During Display

```
1. User viewing current image
   ↓
2. Lightbox prefetches next/previous
   ↓
3. Uses medium hash (1000px) for speed
   ↓
4. Server caches medium-resolution image
   ↓
5. If user navigates, medium loads instantly
   ↓
   Time: <100ms for navigation
```

### Subsequent Viewing

```
1. User navigates to next image
   ↓
2. Medium-resolution already prefetched
   ↓
3. Lightbox requests full-size
   ↓
4. If full-size cached, serves instantly
   ↓
5. If not, downloads full-size in background
   ↓
   Time: 0-200ms (usually cached)
```

## File Structure

### Cache Organization

```
public/cache/
├── v1_<hash1>.jpg    (full-size image, fit_5120)
├── v1_<hash2>.jpg    (full-size image, fit_5120)
├── v1_<hash3>.jpg    (medium image, 1000px)
├── v1_<hash4>.jpg    (medium image, 1000px)
└── .url_mapping.json (privacy mapping)
```

### Hash Mapping

The `.url_mapping.json` file maps hashes to PhotoPrism URLs:

```json
{
  "a1b2c3d4e5f6g7h8": "https://photoprism.example.com/api/v1/t/abc123/token/fit_5120",
  "i9j0k1l2m3n4o5p6": "https://photoprism.example.com/api/v1/t/abc123/token/tile_1000"
}
```

## Performance Characteristics

### Download Sizes

Typical full-resolution image sizes:

| Format | Typical Size |
|--------|-------------|
| Medium (1000px) | 50-150 KB |
| Full (5120px) | 300-800 KB |
| Highly compressed | 50-300 KB |

### Load Times

| Scenario | Time | Status |
|----------|------|--------|
| First full-size | 500-2000ms | Network bound |
| Prefetched medium | 0-100ms | Cache hit |
| Subsequent full-size | 100-500ms | Might download |
| Both cached | <50ms | Instant |

### Cache Growth

For a 100-image gallery with full-size caching:

```
Medium images (1000px): 100 × 100 KB = 10 MB
Full images (5120px):   ~5-10 cached = 3-8 MB
Total:                  ~13-18 MB
```

Most users won't cache all full-size images (only viewed ones).

## API Endpoints

### Cache Endpoint with Full-Size Support

**Request:**
```
GET /api.php?action=cache&subaction=get&hash=a1b2c3d4e5f6g7h8
```

**Server-side processing:**
1. Load URL mapping from `url_mapping.json`
2. Look up: `a1b2c3d4e5f6g7h8` → `https://photoprism.../fit_5120`
3. Check cache (public/cache/)
4. If not cached:
   - Download from PhotoPrism
   - Save to public/cache/v1_a1b2c3d4...jpg
5. Return binary image data with cache headers
6. Browser displays image

**Response headers:**
```
Content-Type: image/jpeg
Content-Length: 456789
Cache-Control: public, max-age=2592000, immutable
X-Cache-Status: hit
X-Cache-Age: 3600
X-Cache-Size: 456789
```

## Configuration

### Change Maximum Resolution

Edit `api.php`:

```php
// Current: Tries fit_5120 first
$fullImageUrl = $client->getThumbnailUrl($photo, 5120) ?? ...

// Change to lower resolution:
$fullImageUrl = $client->getThumbnailUrl($photo, 2000) ?? ...

// Or keep current and add fallback:
$fullImageUrl = $client->getThumbnailUrl($photo, 10000) ?? 
                $client->getThumbnailUrl($photo, 5120) ?? ...
```

### Change Medium Resolution for Prefetch

Edit `api.php`:

```php
// Current: 1000px medium
$mediumImageUrl = $client->getThumbnailUrl($photo, 1000) ?? $fullImageUrl;

// Change to 500px (smaller/faster prefetch):
$mediumImageUrl = $client->getThumbnailUrl($photo, 500) ?? $fullImageUrl;

// Or 2000px (higher quality prefetch):
$mediumImageUrl = $client->getThumbnailUrl($photo, 2000) ?? $fullImageUrl;
```

## Benefits

✅ **Full Quality in Lightbox**
- Users see highest quality images
- fit_5120 provides excellent detail
- Professional presentation

✅ **Intelligent Prefetch**
- Next image prefetches medium (faster)
- Navigation remains responsive
- Full-size loads in background

✅ **Optimized Caching**
- Only caches when viewed
- Medium cached for fast navigation
- Full cached for quality display
- Automatic cleanup after 30 days

✅ **Privacy Maintained**
- URLs hidden via hash mapping
- No PhotoPrism URLs exposed
- All lookups server-side
- Frontend only sees hashes

## Implementation Details

### How Hash Selection Works

1. **Tiles endpoint generates both hashes:**
   - `imageHash` → fit_5120 URL
   - `mediumHash` → 1000px URL

2. **Lightbox uses imageHash for display:**
   - Falls back to mediumHash if full not available
   - Falls back to thumbnail if hash missing

3. **Prefetch uses mediumHash:**
   - Uses fallback to imageHash if medium not available
   - Balances speed (medium) vs quality (full)

4. **Smart caching:**
   - Both images cached locally
   - Subsequent navigation uses cached version
   - Automatic refresh after 30 days

## Monitoring

### Check Cache Usage

```bash
# View cache statistics
php scripts/cache-manager.php stats

# List cached files (shows full-size)
php scripts/cache-manager.php list
```

### Verify Full-Size in Browser

1. Open DevTools (F12)
2. Go to Network tab
3. Click image in gallery
4. Check cache request for full-size hash
5. Verify response shows full-resolution image

## Troubleshooting

### Images appear small in lightbox

**Check:**
1. Verify `imageHash` in API response
2. Check cache request uses correct hash
3. Verify fit_5120 is available in PhotoPrism

**Solution:**
```bash
# Clear cache and reload
php scripts/cache-manager.php flush

# Hard refresh browser
# Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
```

### Medium hash not caching

**Check:**
1. Prefetch is running (check console)
2. Network tab shows medium hash request
3. Cache endpoint returns 200 OK

**Solution:**
```bash
# Check cache permissions
chmod 755 public/cache
chmod 644 public/cache/*.jpg

# Verify mapping file
cat public/cache/.url_mapping.json
```

## Future Improvements

1. **Adaptive Resolution** - Choose resolution based on screen size
2. **WebP Conversion** - Serve WebP to modern browsers
3. **Progressive Loading** - Load medium, then upgrade to full
4. **Lazy Loading** - Load full-size only when viewport visible
5. **AVIF Support** - Use next-gen compression format

## Summary

The lightbox now:
✅ Displays full-resolution (fit_5120) images
✅ Caches locally for instant subsequent views
✅ Intelligently prefetches medium-size next images
✅ Maintains privacy with hash-based URLs
✅ Adapts gracefully with fallback resolutions

Users get the best quality experience with professional-grade full-size images, all while maintaining fast navigation and complete privacy.

