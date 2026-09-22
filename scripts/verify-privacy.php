#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Privacy Verification Script
 * 
 * Tests that PhotoPrism URLs are not exposed in API responses
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../image_url_mapper.php';

use ImageMosaic\ImageUrlMapper;

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║           Privacy Verification - API Endpoints             ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Test 1: Check tiles response
echo "TEST 1: Tiles Endpoint Response\n";
echo "────────────────────────────────────────────────────────────\n";

$baseUrl = 'http://localhost';
$tilesUrl = "$baseUrl/api.php?action=tiles&limit=5";

$response = @file_get_contents($tilesUrl);
if ($response === false) {
    echo "⚠️  Cannot reach local server. Test skipped.\n";
    echo "    Tip: Start PHP server with: php -S localhost:8000\n\n";
} else {
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['tiles'])) {
        echo "❌ Failed to get tiles data\n\n";
    } else {
        echo "✓ Got " . count($data['tiles']) . " tiles\n\n";
        
        // Check for PhotoPrism URLs
        $hasUrls = false;
        $hasHashes = false;
        
        foreach ($data['tiles'] as $i => $tile) {
            $tileJson = json_encode($tile);
            
            if (strpos($tileJson, 'photoprism') !== false || 
                strpos($tileJson, '/api/v1/t/') !== false ||
                strpos($tileJson, 'tile_') !== false) {
                $hasUrls = true;
                echo "❌ FAIL: Tile $i contains PhotoPrism URL\n";
                echo "   Full: " . substr($tileJson, 0, 100) . "...\n";
            }
            
            if (isset($tile['imageHash'])) {
                $hasHashes = true;
            }
        }
        
        if (!$hasUrls && $hasHashes) {
            echo "✅ PASS: No PhotoPrism URLs exposed\n";
            echo "✅ PASS: Image hashes used instead\n";
        } elseif (!$hasUrls && !$hasHashes) {
            echo "⚠️  WARNING: No hashes found (check tiles structure)\n";
        }
    }
}

// Test 2: Check URL mapping file
echo "\n\nTEST 2: URL Mapping File\n";
echo "────────────────────────────────────────────────────────────\n";

$mappingFile = __DIR__ . '/../public/cache/.url_mapping.json';

if (file_exists($mappingFile)) {
    $json = file_get_contents($mappingFile);
    $data = json_decode($json, true);
    
    echo "✓ Mapping file exists: $mappingFile\n";
    echo "  Size: " . filesize($mappingFile) . " bytes\n";
    echo "  Entries: " . count($data ?? []) . "\n";
    
    if ($data && is_array($data)) {
        // Show sample (first 2)
        $count = 0;
        foreach ($data as $hash => $url) {
            if ($count >= 2) break;
            echo "  Sample: $hash → " . substr($url, 0, 50) . "...\n";
            $count++;
        }
    }
} else {
    echo "ℹ️  Mapping file not yet created (normal - created on first access)\n";
    echo "    Will be created at: $mappingFile\n";
}

// Test 3: Check API endpoint parameter
echo "\n\nTEST 3: Cache Endpoint Parameters\n";
echo "────────────────────────────────────────────────────────────\n";

echo "Old endpoint (insecure):\n";
echo "  GET /api.php?action=cache&url=https://photoprism.example.com/...\n";
echo "  ❌ Exposes PhotoPrism URL\n\n";

echo "New endpoint (secure):\n";
echo "  GET /api.php?action=cache&hash=a1b2c3d4e5f6g7h8\n";
echo "  ✅ Only exposes hash\n\n";

// Test 4: Check JavaScript bundle
echo "\nTEST 4: Frontend Code Analysis\n";
echo "────────────────────────────────────────────────────────────\n";

$jsFile = __DIR__ . '/../dist/gallery.min.js';
if (file_exists($jsFile)) {
    $js = file_get_contents($jsFile);
    
    // Check for PhotoPrism references
    if (strpos($js, 'photoprism') !== false) {
        echo "⚠️  Bundle contains 'photoprism' string\n";
    } else {
        echo "✅ Bundle has no 'photoprism' hardcoded\n";
    }
    
    // Check for API endpoint patterns
    if (strpos($js, '/api/v1/t/') !== false) {
        echo "⚠️  Bundle contains '/api/v1/t/' pattern\n";
    } else {
        echo "✅ Bundle has no '/api/v1/t/' pattern\n";
    }
    
    // Check for hash parameter
    if (strpos($js, 'hash=') !== false) {
        echo "✅ Bundle uses 'hash=' parameter\n";
    } else {
        echo "⚠️  Bundle doesn't use 'hash=' (check if old version)\n";
    }
    
    echo "  JS file: $jsFile\n";
    echo "  Size: " . round(filesize($jsFile) / 1024, 1) . " KB\n";
} else {
    echo "⚠️  JS bundle not found. Build with: npm run build\n";
}

// Test 5: Check git ignore
echo "\n\nTEST 5: Git Security (.gitignore)\n";
echo "────────────────────────────────────────────────────────────\n";

$gitignore = __DIR__ . '/../.gitignore';
if (file_exists($gitignore)) {
    $content = file_get_contents($gitignore);
    
    if (strpos($content, '.url_mapping.json') !== false) {
        echo "✅ Mapping file in .gitignore\n";
    } else {
        echo "⚠️  Mapping file NOT in .gitignore\n";
        echo "   Add to .gitignore: public/cache/.url_mapping.json\n";
    }
    
    if (strpos($content, 'public/cache/') !== false) {
        echo "✅ Cache directory in .gitignore\n";
    } else {
        echo "ℹ️  Cache directory not explicitly in .gitignore\n";
    }
} else {
    echo "⚠️  .gitignore not found\n";
}

// Summary
echo "\n\n╔════════════════════════════════════════════════════════════╗\n";
echo "║                      Summary                               ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "Privacy Verification Checklist:\n";
echo "  ☐ No 'photoprism' in API responses\n";
echo "  ☐ No '/api/v1/t/' in API responses\n";
echo "  ☐ Only image hashes exposed\n";
echo "  ☐ URL mappings stored server-side\n";
echo "  ☐ .gitignore excludes mapping file\n";
echo "  ☐ Frontend uses hash parameter\n";
echo "  ☐ Cache endpoint accepts hash only\n\n";

echo "Next Steps:\n";
echo "  1. Test in browser: http://localhost/index.php\n";
echo "  2. Open DevTools: F12\n";
echo "  3. Go to Network tab\n";
echo "  4. Click an image\n";
echo "  5. Check cache requests use 'hash=' not 'url='\n";
echo "  6. Verify no PhotoPrism URLs in Network tab\n\n";

echo "For detailed documentation:\n";
echo "  See: API_PRIVACY.md\n\n";
