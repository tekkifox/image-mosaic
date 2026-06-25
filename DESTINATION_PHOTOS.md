# Destination Photo Integration

Featured photos from PhotoPrism have been downloaded and integrated into each destination section on the website.

## What Was Added

### 1. PhotoPrism Client Methods

**`getFeaturedPhoto($album, $category)`**
- Fetches a single featured photo from a specific album or category
- Returns the newest photo matching the criteria
- Located in: `photoprism_client.php`

### 2. API Endpoint

**`GET /api.php?action=featured-photo`**

Parameters:
- `category` (optional) - Album category
- `album` (optional) - Specific album name
- `debug` (optional) - Enable debug output

Response:
```json
{
  "photo": { /* photo object */ },
  "thumbnail_url": "https://...",
  "debug_info": { /* optional */ }
}
```

Example:
```bash
curl "http://example.com/api.php?action=featured-photo&album=Bangkok,%20Thailand"
```

### 3. Download Script

**`scripts/download-destination-photos.php`**

Downloads featured photos from PhotoPrism and saves them locally.

**Usage:**
```bash
php scripts/download-destination-photos.php
```

**Destinations Configured:**
- Southeast Asia → Bangkok, Thailand
- South Korea → Seoul, South Korea
- Japan → Tokyo, Japan
- Hong Kong → Hong Kong, China
- Australia → Australia

**Output Location:**
```
public/images/destinations/
├── southeast-asia.jpg  (39 KB)
├── south-korea.jpg     (32 KB)
├── japan.jpg           (26 KB)
├── hong-kong.jpg       (25 KB)
└── australia.jpg       (54 KB)
```

### 4. HTML Integration

Updated destination section images in `index.php`:

**Before:**
```html
<img src="data:image/svg+xml,...">  <!-- placeholder SVG -->
```

**After:**
```html
<img src="/public/images/destinations/southeast-asia.jpg" alt="Southeast Asia">
```

## File Structure

```
project-root/
├── photoprism_client.php          (added getFeaturedPhoto method)
├── api.php                         (added featured-photo endpoint)
├── scripts/
│   └── download-destination-photos.php  (download script)
├── public/
│   └── images/
│       └── destinations/           (created)
│           ├── southeast-asia.jpg
│           ├── south-korea.jpg
│           ├── japan.jpg
│           ├── hong-kong.jpg
│           └── australia.jpg
└── index.php                       (updated image references)
```

## How to Update Destination Photos

If you want to change which photo is displayed for a destination:

1. **Edit the album names** in `scripts/download-destination-photos.php`:
   ```php
   'southeast-asia' => [
       'album' => 'Bangkok, Thailand',  // Change this
   ],
   ```

2. **Run the download script** again:
   ```bash
   php scripts/download-destination-photos.php
   ```

3. The images in `public/images/destinations/` will be updated automatically.

## Features

✅ **Live PhotoPrism Integration** - Photos sourced directly from your PhotoPrism instance
✅ **Automatic Caching** - Downloaded and stored locally for fast page loads
✅ **Easy Updates** - Simple script to refresh all destination photos
✅ **Responsive Images** - 500px thumbnails work on all screen sizes
✅ **Newest Photos** - Uses "newest" ordering to show recent travel photos

## Performance

- **Image Sizes**: 25-54 KB each (highly optimized by PhotoPrism)
- **Total Size**: ~176 KB for all 5 destinations
- **Load Time**: Minimal - served locally, not from PhotoPrism each time
- **Caching**: Images can be cached by browser/CDN indefinitely

## Customization

### Change Image Resolution

Edit the `getThumbnailUrl` call in the download script:
```php
$thumbUrl = $client->getThumbnailUrl($photo, 500);  // 500px
// Change to: 1000, 224, etc.
```

### Change Photo Selection Criteria

Modify the order in `download-destination-photos.php`:
```php
$params = ['limit' => 1, 'order' => 'newest'];  // Change 'newest' to 'random', 'oldest', etc.
```

### Add More Destinations

Add entries to the `$destinations` array:
```php
'new-destination' => [
    'name' => 'Destination Name',
    'category' => 'Travelling',
    'album' => 'Album Name in PhotoPrism',
],
```

## API Versioning

The featured-photo endpoint can be used programmatically:

```javascript
// JavaScript
fetch('api.php?action=featured-photo&album=Bangkok%2C%20Thailand')
  .then(r => r.json())
  .then(data => {
    console.log('Thumbnail:', data.thumbnail_url);
  });
```

```php
// PHP
$client->getFeaturedPhoto('Bangkok, Thailand', '');
```

## Troubleshooting

### Script Returns "No photos found"

1. Check the album name matches exactly in PhotoPrism
2. List available albums to find correct names:
   ```bash
   php -r "
   require 'photoprism_client.php';
   \$client = new PhotoPrismClient(include 'config.php');
   \$albums = \$client->getAlbumsByCategory('Travelling', 100);
   foreach (\$albums as \$a) echo \$a['Title'] . \"\\n\";
   "
   ```

### Images not updating

1. Delete old images: `rm public/images/destinations/*`
2. Re-run the download script: `php scripts/download-destination-photos.php`

### 404 errors for images

Ensure web server has proper permissions:
```bash
chmod 755 public/images/destinations
chmod 644 public/images/destinations/*.jpg
```
