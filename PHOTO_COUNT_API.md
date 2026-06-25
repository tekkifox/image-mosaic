# Photo Count API Endpoint

The application now includes a new API endpoint to fetch the total count of photos available in PhotoPrism, and the hero section "Photos" statistic is now dynamically populated with live data.

## New API Endpoint

### `GET /api.php?action=photo-count`

Returns the total count of photos matching the search criteria.

#### Parameters
- `category` (optional) - Filter by album category (e.g., "Travelling")
- `album` (optional) - Filter by specific album name
- `debug` (optional) - Enable debug output

#### Response
```json
{
  "count": 247,
  "debug_info": {
    "connection": { ... }
  }
}
```

#### Example Usage
```bash
# Get count for all photos in "Travelling" category
curl "http://example.com/api.php?action=photo-count&category=Travelling"

# Get count for specific album
curl "http://example.com/api.php?action=photo-count&album=Bangkok"

# With debug output
curl "http://example.com/api.php?action=photo-count&category=Travelling&debug=1"
```

## Implementation Details

### PhotoPrism Client Method

Added `getPhotoCount()` method to `photoprism_client.php`:

```php
public function getPhotoCount(
    string $album = '',
    string $category = ''
): int
```

**Features:**
- Respects category and album filters
- Extracts count from PhotoPrism API response headers (`X-Count`)
- Fallback to response body parsing if headers unavailable
- Handles album title to UID conversion for category filtering

### API Endpoint Handler

Added `photo-count` action to `api.php`:

```php
if ($action === 'photo-count') {
    $count = $client->getPhotoCount(
        $_GET['album'] ?? '',
        $_GET['category'] ?? ''
    );
    respondJson([
        'count' => $count,
        'debug_info' => $responseDebug,
    ], $debugMode);
}
```

### Frontend Integration

#### HTML Changes
Updated `index.php` hero section:
```html
<div class="stat-number" id="photo-count-stat">144</div>
```

Added inline script to fetch and update the count:
```javascript
fetch('api.php?action=photo-count&category=Travelling')
    .then(r => r.json())
    .then(data => {
        if (data && typeof data.count === 'number') {
            const photoCountStat = document.getElementById('photo-count-stat');
            if (photoCountStat) {
                photoCountStat.textContent = data.count;
            }
        }
    })
    .catch(err => console.error('Failed to fetch photo count:', err));
```

**How it works:**
1. Page loads with hardcoded fallback value of 144
2. Inline script fetches live count from API
3. If successful, updates the stat display with live count
4. Falls back gracefully to 144 if API call fails

#### Gallery Component

Updated `public/gallery.js` Gallery component to also fetch the count:

```javascript
const [photoCount, setPhotoCount] = useState(0);

useEffect(()=>{
  // Fetch photo count for stats
  fetch('api.php?action=photo-count&category=Travelling')
    .then(r=>r.json())
    .then(data=>{
      if(data && typeof data.count === 'number'){
        setPhotoCount(data.count);
      }
    })
    .catch(err=>console.error('Failed to fetch photo count:', err));
  
  // ... rest of useEffect
}, []);
```

## How It Works

1. **Page Load**: Hero section displays with fallback "144" value
2. **Inline Script**: Immediately fetches actual count for "Travelling" category
3. **Update**: DOM updates with live count (if different from 144)
4. **Graceful Degradation**: If API fails, fallback value is retained

## Data Flow

```
User visits page
    ↓
HTML renders with hardcoded 144
    ↓
Inline script executes
    ↓
fetch('api.php?action=photo-count&category=Travelling')
    ↓
PhotoPrism client constructs query
    ↓
PhotoPrism API returns count in headers
    ↓
API response: { count: 247 }
    ↓
JavaScript updates DOM
    ↓
Stat displays: "247 Photos"
```

## Benefits

✅ **Live Data**: Always shows current photo count from PhotoPrism
✅ **No Hardcoding**: Stats are dynamic, not static
✅ **Fast Loading**: Fallback value loads instantly; live count updates asynchronously
✅ **Error Handling**: Graceful fallback if API unavailable
✅ **Flexible**: Can filter by category or album as needed
✅ **Efficient**: Only fetches count, not entire photo list

## Usage Examples

### JavaScript Fetch
```javascript
// Get count for Travelling category
fetch('api.php?action=photo-count&category=Travelling')
  .then(r => r.json())
  .then(data => console.log(`Total photos: ${data.count}`));

// Get count for specific album
fetch('api.php?action=photo-count&album=Bangkok')
  .then(r => r.json())
  .then(data => console.log(`Bangkok photos: ${data.count}`));
```

### PHP
```php
$client = new PhotoPrismClient($config);
$count = $client->getPhotoCount('', 'Travelling');
echo "Total photos: " . $count;
```

### cURL
```bash
curl -s "http://example.com/api.php?action=photo-count&category=Travelling" \
  | jq '.count'
```

## Debugging

Enable debug output to see request details:

```bash
curl "http://example.com/api.php?action=photo-count&category=Travelling&debug=1"
```

Response will include:
```json
{
  "count": 247,
  "debug_info": {
    "connection": {
      "baseUrl": "http://photoprism.example.com",
      "hasApiKey": true,
      "authType": "api_key"
    }
  }
}
```

## Performance Notes

- **Lightweight**: Only fetches metadata (count), not actual photos
- **Cache-Safe**: PhotoPrism caches count queries efficiently
- **Non-Blocking**: Async fetch doesn't block page rendering
- **Fallback Safe**: Page fully functional even if API unavailable

## Future Enhancements

Possible improvements:
- Cache count value in browser localStorage (with TTL)
- Add count endpoint for other categories
- Display counts per destination in stats
- Add loading animation while fetching count
- Cache count server-side (e.g., Redis, Memcached)
