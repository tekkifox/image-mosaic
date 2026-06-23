<?php

namespace ImageMosaic;

function respondJson(array $data, bool $debugMode = false): void
{
    if ($debugMode) {
        $data['debug'] = [
            'timestamp' => date('c'),
            'requestUri' => $_SERVER['REQUEST_URI'] ?? '',
            'phpVersion' => PHP_VERSION,
        ];
    }

    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
