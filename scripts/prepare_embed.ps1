#!/usr/bin/env pwsh
# Prepare embedded assets: prefer dist (frontend build) then fallback to repo public/, and copy docs
$distSrc = Join-Path (Get-Location) 'dist'
$rootPublic = Join-Path (Get-Location) 'public'
$publicDest = Join-Path (Get-Location) 'cmd\api\public'
$docsSrc = Join-Path (Get-Location) 'docs'
$docsDest = Join-Path (Get-Location) 'cmd\api\docs'

if (Test-Path $publicDest) {
    Remove-Item -Recurse -Force $publicDest
}
# Ensure destination exists
New-Item -ItemType Directory -Force -Path $publicDest | Out-Null

if (Test-Path $distSrc) {
    # If public/ exists, merge its contents into dist so dist contains index and images
    if (Test-Path $rootPublic) {
        Write-Output "Merging $rootPublic into $distSrc"
        Get-ChildItem -Path $rootPublic -Force | ForEach-Object {
            $src = $_.FullName
            Copy-Item -Path $src -Destination $distSrc -Recurse -Force
        }
    }
    Write-Output "Copying contents of $distSrc -> $publicDest"
    Get-ChildItem -Path $distSrc -Force | ForEach-Object {
        $src = $_.FullName
        Copy-Item -Path $src -Destination $publicDest -Recurse -Force
    }
} elseif (Test-Path $rootPublic) {
    Write-Output "Copying contents of $rootPublic -> $publicDest"
    Get-ChildItem -Path $rootPublic -Force | ForEach-Object {
        $src = $_.FullName
        Copy-Item -Path $src -Destination $publicDest -Recurse -Force
    }
} else {
    Write-Error "No dist or public directory found. Run npm run build or ensure public/ exists."
}

if (Test-Path $docsDest) {
    Remove-Item -Recurse -Force $docsDest
}
New-Item -ItemType Directory -Force -Path $docsDest | Out-Null
if (Test-Path $docsSrc) {
    Write-Output "Copying $docsSrc -> $docsDest"
    Get-ChildItem -Path $docsSrc -Force | ForEach-Object {
        $src = $_.FullName
        Copy-Item -Path $src -Destination $docsDest -Recurse -Force
    }
} else {
    Write-Output "No docs directory found; run swag init to generate docs if needed."
}

Write-Output "Prepared embed assets."
