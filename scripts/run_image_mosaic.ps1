#!/usr/bin/env pwsh
# Run image-mosaic with environment variables loaded from .env
$envFile = Join-Path (Get-Location) ".env"
if (Test-Path $envFile) {
    Get-Content $envFile | ForEach-Object {
        if ($_ -match '^\s*([^#=]+)=\s*(.*)\s*$') {
            $name = $matches[1].Trim()
            $val = $matches[2].Trim()
            if ($val.Length -ge 2 -and $val.StartsWith('"') -and $val.EndsWith('"')) {
                $val = $val.Substring(1, $val.Length - 2)
            }
            if ($val.Length -ge 2 -and $val.StartsWith("'") -and $val.EndsWith("'")) {
                $val = $val.Substring(1, $val.Length - 2)
            }
            Write-Output "Set $name=$val"
            [System.Environment]::SetEnvironmentVariable($name, $val, "Process")
        }
    }
}

Write-Output "Starting image-mosaic with environment from .env"

$binary = Join-Path (Get-Location) 'image-mosaic.exe'
if (-not (Test-Path $binary)) {
    $binary = Join-Path (Get-Location) 'image-mosaic'
}

if (-not (Test-Path $binary)) {
    Write-Error "Binary not found: $binary"
    exit 1
}

& $binary
