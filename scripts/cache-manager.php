#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../cache_manager.php';

use ImageMosaic\CacheManager;

$command = $argv[1] ?? 'help';
$cache = new CacheManager('public/cache');

function printHelp(): void
{
    echo <<<'EOF'
╔════════════════════════════════════════════════════════════════════╗
║              Image Mosaic Cache Manager CLI Tool                   ║
╚════════════════════════════════════════════════════════════════════╝

USAGE:
  php scripts/cache-manager.php <command> [options]

COMMANDS:

  stats
    Display cache statistics (file count, total size, etc.)

  cleanup [--ttl DAYS]
    Remove expired cache files
    Options:
      --ttl DAYS    Remove files older than N days (default: 30)

  flush
    Clear all cache files (irreversible!)

  info <url_hash>
    Show cache metadata for a specific cached URL

  list [--limit N]
    List all cached files
    Options:
      --limit N     Show only first N files (default: 20)

  help
    Show this help message

EXAMPLES:

  # Check cache status
  $ php scripts/cache-manager.php stats

  # Remove files older than 7 days
  $ php scripts/cache-manager.php cleanup --ttl 7

  # Clear all cache
  $ php scripts/cache-manager.php flush

  # List cached files
  $ php scripts/cache-manager.php list --limit 10

EOF;
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}

function formatDate(int $timestamp): string
{
    return date('Y-m-d H:i:s', $timestamp);
}

match ($command) {
    'stats' => function () use ($cache) {
        $stats = $cache->getStats();
        
        echo "\n╔════════════════════════════════════════════════════════════╗\n";
        echo "║                     Cache Statistics                         ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";
        
        echo "Cache Directory: " . $stats['cache_dir'] . "\n";
        echo "Files Cached:    " . $stats['file_count'] . "\n";
        echo "Total Size:      " . formatBytes($stats['total_size']) . " ({$stats['total_size_mb']} MB)\n";
        echo "Default TTL:     " . $stats['default_ttl_days'] . " days\n";
        
        if ($stats['newest_file']) {
            echo "\nNewest File:  " . $stats['newest_file'] . "\n";
            echo "              Created: " . formatDate($stats['newest_time']) . "\n";
        }
        
        if ($stats['oldest_file']) {
            echo "\nOldest File:  " . $stats['oldest_file'] . "\n";
            echo "              Created: " . formatDate($stats['oldest_time']) . "\n";
        }
        
        echo "\n";
    },
    
    'cleanup' => function () use ($cache, $argv) {
        $ttlDays = 30;
        
        for ($i = 2; $i < count($argv); $i++) {
            if ($argv[$i] === '--ttl' && isset($argv[$i + 1])) {
                $ttlDays = (int) $argv[$i + 1];
                break;
            }
        }
        
        $ttlSeconds = $ttlDays * 24 * 60 * 60;
        $removed = $cache->cleanup($ttlSeconds);
        
        echo "\n╔════════════════════════════════════════════════════════════╗\n";
        echo "║                    Cache Cleanup Complete                   ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";
        echo "Files Removed:   $removed\n";
        echo "TTL Threshold:   $ttlDays days\n";
        echo "\n";
    },
    
    'flush' => function () use ($cache) {
        echo "\n⚠️  WARNING: This will delete ALL cached files!\n";
        echo "Are you sure? (type 'yes' to confirm): ";
        
        $input = trim(fgets(STDIN));
        if ($input !== 'yes') {
            echo "Cancelled.\n\n";
            return;
        }
        
        $removed = $cache->flush();
        
        echo "\n╔════════════════════════════════════════════════════════════╗\n";
        echo "║                    Cache Flushed                            ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";
        echo "Files Deleted:   $removed\n";
        echo "\n";
    },
    
    'list' => function () use ($cache, $argv) {
        $limit = 20;
        
        for ($i = 2; $i < count($argv); $i++) {
            if ($argv[$i] === '--limit' && isset($argv[$i + 1])) {
                $limit = (int) $argv[$i + 1];
                break;
            }
        }
        
        $cacheDir = 'public/cache';
        $files = array_diff(scandir($cacheDir) ?: [], ['.', '..', '.locks']);
        
        echo "\n╔════════════════════════════════════════════════════════════╗\n";
        echo "║                    Cached Files                             ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";
        
        if (empty($files)) {
            echo "No cached files.\n\n";
            return;
        }
        
        // Sort by modification time (newest first)
        usort($files, function ($a, $b) use ($cacheDir) {
            $aTime = filemtime($cacheDir . '/' . $a);
            $bTime = filemtime($cacheDir . '/' . $b);
            return $bTime - $aTime;
        });
        
        $shown = 0;
        foreach ($files as $file) {
            if ($file === '.locks' || is_dir($cacheDir . '/' . $file)) {
                continue;
            }
            
            if ($shown >= $limit) {
                echo "\n... and " . (count($files) - $limit) . " more files\n";
                break;
            }
            
            $path = $cacheDir . '/' . $file;
            $size = filesize($path);
            $mtime = filemtime($path);
            
            printf(
                "%-40s %10s  %s\n",
                substr($file, 0, 40),
                formatBytes($size),
                formatDate($mtime)
            );
            
            $shown++;
        }
        
        echo "\n";
    },
    
    'help', '--help', '-h' => fn() => printHelp(),
    
    default => function () use ($command) {
        echo "Unknown command: $command\n";
        echo "Run 'php scripts/cache-manager.php help' for usage\n\n";
        exit(1);
    }
}();
