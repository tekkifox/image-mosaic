<?php
/**
 * Download and cache featured photos for each destination
 * Run this script to fetch and store destination photos locally
 * 
 * Usage: php scripts/download-destination-photos.php
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../photoprism_client.php';

use ImageMosaic\PhotoPrismClient;

$config = include __DIR__ . '/../config.php';
$client = new PhotoPrismClient($config);

// Define destinations and their categories
$destinations = [
    'southeast-asia' => [
        'name' => 'Southeast Asia',
        'category' => 'Travelling',
        'album' => 'Bangkok, Thailand',
    ],
    'south-korea' => [
        'name' => 'South Korea',
        'category' => 'Travelling',
        'album' => 'Seoul, South Korea',
    ],
    'japan' => [
        'name' => 'Japan',
        'category' => 'Travelling',
        'album' => 'Tokyo, Japan',
    ],
    'hong-kong' => [
        'name' => 'Hong Kong',
        'category' => 'Travelling',
        'album' => 'Hong Kong, China',
    ],
    'australia' => [
        'name' => 'Australia',
        'category' => 'Travelling',
        'album' => 'Australia',
    ],
];

// Create destination images directory
$imageDir = __DIR__ . '/../public/images/destinations';
if (!is_dir($imageDir)) {
    mkdir($imageDir, 0755, true);
    echo "✓ Created directory: $imageDir\n";
}

$downloaded = 0;
$failed = 0;

foreach ($destinations as $key => $destination) {
    echo "\nFetching featured photo for: {$destination['name']}\n";
    echo "  Category: {$destination['category']}, Album: {$destination['album']}\n";
    
    try {
        // Fetch a random photo from the album instead of newest
        // Get multiple photos and pick a random one for variety
        $params = ['limit' => 10, 'order' => 'random'];
        $photos = $client->listPhotos(10, $destination['album'], $destination['category'], 'random');
        
        if (!is_array($photos) || count($photos) === 0) {
            echo "  ✗ No photos found\n";
            $failed++;
            continue;
        }
        
        // Pick a random photo from the results
        $photo = $photos[array_rand($photos)];
        
        // Get thumbnail URL
        $thumbUrl = $client->getThumbnailUrl($photo, 500);
        
        if ($thumbUrl === null) {
            echo "  ✗ Could not generate thumbnail URL\n";
            $failed++;
            continue;
        }
        
        echo "  → Thumbnail URL: $thumbUrl\n";
        
        // Download the image
        $imageData = file_get_contents($thumbUrl);
        if ($imageData === false) {
            echo "  ✗ Failed to download image\n";
            $failed++;
            continue;
        }
        
        // Determine file extension from content-type or URL
        $ext = 'jpg';
        if (strpos($thumbUrl, '.png') !== false) {
            $ext = 'png';
        }
        
        // Save the image
        $filePath = "$imageDir/$key.$ext";
        $bytes = file_put_contents($filePath, $imageData);
        
        if ($bytes === false) {
            echo "  ✗ Failed to save image to: $filePath\n";
            $failed++;
            continue;
        }
        
        echo "  ✓ Saved: $filePath (" . ($bytes / 1024) . " KB)\n";
        $downloaded++;
        
    } catch (Throwable $e) {
        echo "  ✗ Error: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "Download Summary\n";
echo str_repeat('=', 50) . "\n";
echo "Successfully downloaded: $downloaded\n";
echo "Failed: $failed\n";
echo "\nImages saved to: $imageDir/\n";
echo "\nTo use in HTML, reference:\n";
foreach ($destinations as $key => $destination) {
    echo "  <img src=\"/public/images/destinations/$key.jpg\" alt=\"{$destination['name']}\">\n";
}
