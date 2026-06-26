# Cache Refresh Guide

How to clear and refresh the cache in Image Mosaic Gallery.

## Cache Types

Your app caches three types of data:

1. **Processed Tiles** (5 minutes TTL)
   - File: `public/cache/.tiles_*_processed.json`
   - Contains: Photo tile data with URLs and metadata
   - Refreshes automatically every 5 minutes

2. **Photo Count** (5 minutes TTL)
   - File: `public/cache/.count_*.json`
   - Contains: Total photo count from PhotoPrism
   - Refreshes automatically every 5 minutes

3. **Image Thumbnails** (30 days, persistent)
   - Directory: `public/cache/`
   - Contains: Downloaded thumbnail images
   - Only cleared manually

## Quick Cache Clear

### Option 1: URL Parameter (Development)

Add `?refresh=true` to any tile request:

```
http://localhost:8080/api.php?action=tiles&refresh=true
```

This bypasses the cache for that request but doesn't delete files.

### Option 2: Delete Cache Files (Fastest)

SSH into your server or container:

```bash
# Delete all cache files
rm -f /app/public/cache/.tiles_*
rm -f /app/public/cache/.count_*

# Or delete specific type:
rm -f /app/public/cache/.tiles_*_processed.json  # Tile cache only
rm -f /app/public/cache/.count_*.json            # Count cache only
```

No restart needed - cache is checked on every API request.

### Option 3: Browser Reload (Wait for TTL)

The cache automatically expires after:
- Tiles: 5 minutes
- Photo count: 5 minutes

Just wait or reload the page after 5 minutes.

## In Docker Container

### From Host Machine

```bash
# Get container ID
CONTAINER_ID=$(docker ps | grep image-mosaic | awk '{print $1}')

# Clear all cache
docker exec $CONTAINER_ID rm -f /app/public/cache/.tiles_*
docker exec $CONTAINER_ID rm -f /app/public/cache/.count_*
```

### Inside Container

```bash
# SSH/exec into container
docker exec -it <container_id> /bin/sh

# Clear cache
rm -f /app/public/cache/.tiles_*
rm -f /app/public/cache/.count_*

# Exit
exit
```

## In Portainer

### Using UI

1. Go to **Containers**
2. Click `image-mosaic` container
3. Click **Console** tab
4. Run commands:

```bash
rm -f /app/public/cache/.tiles_*
rm -f /app/public/cache/.count_*
```

### Using Portainer Exec

1. Go to **Containers**
2. Click `image-mosaic` container
3. Click **Exec** tab
4. Command: `rm -f /app/public/cache/.tiles_* /app/public/cache/.count_*`
5. Click **Execute**

## Verify Cache State

### Check Cache Files

```bash
# SSH to server
ssh user@your-server

# List cache files
ls -lah /path/to/app/public/cache/

# Show file sizes and modification times
ls -lah /path/to/app/public/cache/.[a-z]*
```

### Check Cache Sizes

```bash
# Total cache size
du -sh /path/to/app/public/cache/

# Individual cache types
du -sh /path/to/app/public/cache/.tiles_*
du -sh /path/to/app/public/cache/.count_*
```

### View Cache Content

```bash
# View tiles cache (formatted JSON)
cat /path/to/app/public/cache/.tiles_*_processed.json | jq '.' | head -50

# View count cache
cat /path/to/app/public/cache/.count_*.json
```

## Automatic Cache Refresh

### TTL (Time To Live)

Cache automatically refreshes:
- **Tiles**: Every 5 minutes (300 seconds)
- **Photo Count**: Every 5 minutes (300 seconds)

### How TTL Works

1. File is created/updated
2. App checks modification time on every request
3. If `time() - file.mtime < TTL` → use cache
4. If expired → fetch from PhotoPrism, update cache

### Change TTL

Edit `api.php` line 44:

```php
$tilesCacheTTL = 300;  // Change to desired seconds
// e.g., 600 = 10 minutes, 60 = 1 minute, 3600 = 1 hour
```

Then redeploy container.

## Cache Behavior

### Cache Hit
- API returns cached data instantly
- No PhotoPrism API calls
- Response time: ~20ms
- Debug output: `"cache": "hit_tiles"`

### Cache Miss
- PhotoPrism API called for fresh data
- New cache file created/updated
- Response time: ~2-3 seconds
- Debug output: `"cache": "miss"`

### Expired Cache
- File exists but older than TTL
- Treated as cache miss
- Fresh data fetched
- Cache file updated

## Debug Cache Status

Add `?debug=true` to see cache info:

```
http://localhost:8080/api.php?action=tiles&debug=true
```

Response includes:

```json
{
  "tiles": [...],
  "debug_info": {
    "cache": "hit_tiles"  // or "miss"
  }
}
```

## Troubleshooting

### Cache Not Clearing

Check file permissions:

```bash
ls -l /path/to/app/public/cache/

# Should be readable/writable by www-data (in Docker)
# or your app user (on host)
```

Fix permissions:

```bash
chmod 755 /path/to/app/public/cache/
chmod 644 /path/to/app/public/cache/.*
```

### Cache Gets Too Large

The cache shouldn't grow much since:
- Tiles cache: Single file (~100KB) per category
- Count cache: Single file (~100 bytes) per category
- Thumbnails: Only cached if accessed

Monitor size:

```bash
du -sh /path/to/app/public/cache/

# If too large, delete thumbnail cache:
rm -f /path/to/app/public/cache/*.{jpg,png,webp}
```

### Photos Not Updating

If photos in PhotoPrism are updated but gallery doesn't show them:

1. Clear tiles cache:
   ```bash
   rm -f /app/public/cache/.tiles_*
   ```

2. Clear count cache:
   ```bash
   rm -f /app/public/cache/.count_*
   ```

3. Reload gallery in browser

Photos should now be fresh from PhotoPrism.

## Cache Directory Structure

```
public/cache/
├── .tiles_abc123_processed.json     # Main tiles cache
├── .count_abc123.json               # Photo count cache
├── .url_mapping.json                # Image URL mapping
├── image1.jpg                       # Downloaded thumbnail
├── image2.jpg
└── ...
```

Files starting with `.` are cache metadata. Other files are thumbnail images.

## Performance Impact

### With Cache
- First request: ~2.5 seconds (cache miss)
- Subsequent requests: ~20ms (cache hit)
- 5 minutes later: next miss, then hits again

### Without Cache
- Every request: ~2.5 seconds (no cache)
- Slower gallery experience
- More load on PhotoPrism API

## Best Practices

✅ **DO:**
- Let cache expire naturally (5 minute TTL)
- Clear cache during development
- Monitor cache size periodically
- Check cache in debug output

❌ **DON'T:**
- Delete cache while requests are processing
- Set TTL too low (<60 seconds)
- Delete cache unnecessarily in production
- Manually edit cache files

## API Reference

### Tile Cache

**File Pattern:** `.tiles_{category}_{album}_{order}_processed.json`

**Size:** ~50-150KB per file

**TTL:** 300 seconds (5 minutes)

**Content:** Array of 144 photo objects with:
- Photo ID
- Title
- URLs (thumbnail, medium)
- Album info
- Metadata

### Count Cache

**File Pattern:** `.count_{category}_{album}.json`

**Size:** ~100 bytes

**TTL:** 300 seconds (5 minutes)

**Content:** Simple JSON with photo count

## Related Files

- `api.php` - Cache logic (lines 40-70)
- `cache_manager.php` - Cache helper functions
- `config.php` - Cache configuration
- `.env.example` - Environment variables

## Support

For cache issues:
1. Check `public/cache/` directory exists and is writable
2. Check file permissions (755 directory, 644 files)
3. Check free disk space
4. Review `api.php` debug output with `?debug=true`
5. Check Docker volume mounts if using containers
