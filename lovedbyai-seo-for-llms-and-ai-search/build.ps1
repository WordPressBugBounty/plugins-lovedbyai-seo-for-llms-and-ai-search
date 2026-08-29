# Script to build WordPress plugin zip file for distribution (Windows)
# Mirrors build.sh: version check, phpcs, production deps, zip with exclusions.
#
# Usage (from packages/wp-plugin):
#   .\build.ps1
#
# Or from repo root:
#   .\packages\wp-plugin\build.ps1

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

Write-Host "Checking version consistency..." -ForegroundColor Cyan
$pluginFile = Get-Content "lovedbyai-plugin.php" -Raw
$readmeFile = Get-Content "readme.txt" -Raw

if ($pluginFile -notmatch '(?m)^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)') {
    Write-Host "Failed to extract version from lovedbyai-plugin.php" -ForegroundColor Red
    exit 1
}
$pluginVersion = $Matches[1]

if ($readmeFile -notmatch '(?m)^Stable tag:\s*(\d+\.\d+\.\d+)') {
    Write-Host "Failed to extract version from readme.txt" -ForegroundColor Red
    exit 1
}
$readmeVersion = $Matches[1]

if ($pluginVersion -ne $readmeVersion) {
    Write-Host "Version mismatch detected!" -ForegroundColor Red
    Write-Host "   Plugin version (lovedbyai-plugin.php): $pluginVersion"
    Write-Host "   Readme version (readme.txt): $readmeVersion"
    Write-Host "   Please update both files to use the same version before building."
    exit 1
}

Write-Host "Version check passed! Both files use version $pluginVersion" -ForegroundColor Green

Write-Host "Installing all dependencies (including dev) for code standards check..." -ForegroundColor Cyan
& composer install --optimize-autoloader
if ($LASTEXITCODE -ne 0) {
    Write-Host "Failed to install dependencies." -ForegroundColor Red
    exit 1
}

Write-Host "Running PHP CodeSniffer to verify code standards..." -ForegroundColor Cyan
& .\vendor\bin\phpcs --standard=phpcs.xml
if ($LASTEXITCODE -ne 0) {
    Write-Host "PHP CodeSniffer found issues. Please fix them before building." -ForegroundColor Red
    exit 1
}
Write-Host "Code standards check passed!" -ForegroundColor Green

Write-Host "Installing production dependencies only (removing dev dependencies)..." -ForegroundColor Cyan
& composer install --no-dev --optimize-autoloader
if ($LASTEXITCODE -ne 0) {
    Write-Host "Failed to install production dependencies." -ForegroundColor Red
    exit 1
}
Write-Host "Production dependencies installed!" -ForegroundColor Green

$zipName = "lovedbyai-seo-for-llms-and-ai-search.zip"
if (Test-Path $zipName) {
    Remove-Item $zipName -Force
    Write-Host "Removed existing $zipName"
}

Write-Host "Creating zip file..." -ForegroundColor Cyan
$tempDir = Join-Path $env:TEMP "geoguru-plugin-build-$(Get-Random)"
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

try {
    # Copy plugin files, excluding dev/build artifacts (mirror build.sh exclusions)
    $excludeDirs = @("__MACOSX", ".git", "assets", "logs")
    $excludeFiles = @(".env", ".env.example", ".env.template", ".gitignore", "phpcs.xml", "build.sh", "build.ps1", "build.bat", ".DS_Store")
    $excludePatterns = @("*.zip", ".gitkeep")

    $items = Get-ChildItem -Path . -Force | Where-Object {
        $name = $_.Name
        if ($_.PSIsContainer) {
            if ($excludeDirs -contains $name) { return $false }
            return $true
        }
        if ($excludeFiles -contains $name) { return $false }
        foreach ($p in $excludePatterns) {
            if ($name -like $p) { return $false }
        }
        return $true
    }

    foreach ($item in $items) {
        $dest = Join-Path $tempDir $item.Name
        if ($item.PSIsContainer) {
            Copy-Item -Path $item.FullName -Destination $dest -Recurse -Force
            # Remove excluded content inside copied dirs
            Get-ChildItem -Path $dest -Recurse -Force -ErrorAction SilentlyContinue | Where-Object {
                $_.Name -eq ".DS_Store" -or $_.Name -eq ".gitkeep"
            } | Remove-Item -Force -Recurse -ErrorAction SilentlyContinue
        } else {
            Copy-Item -Path $item.FullName -Destination $dest -Force
        }
    }

    # Create zip using .NET ZipArchive to ensure forward-slash paths (Linux/WordPress compatible)
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zipPath = Join-Path $PSScriptRoot $zipName
    $basePath = (Resolve-Path $tempDir).Path
    $zipStream = [System.IO.File]::Create($zipPath)
    $archive = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem -Path $tempDir -Recurse -File | ForEach-Object {
            $entryPath = $_.FullName.Substring($basePath.Length + 1) -replace '\\', '/'
            $entry = $archive.CreateEntry($entryPath, [System.IO.Compression.CompressionLevel]::Optimal)
            $entryStream = $entry.Open()
            $fileStream = [System.IO.File]::OpenRead($_.FullName)
            $fileStream.CopyTo($entryStream)
            $fileStream.Close()
            $entryStream.Close()
        }
    } finally {
        $archive.Dispose()
        $zipStream.Dispose()
    }

    $size = (Get-Item $zipName).Length
    $sizeStr = if ($size -ge 1MB) { "{0:N2} MB" -f ($size / 1MB) } else { "{0:N2} KB" -f ($size / 1KB) }
    Write-Host "Successfully created $zipName" -ForegroundColor Green
    Write-Host "Zip file size: $sizeStr"
} finally {
    if (Test-Path $tempDir) {
        Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}

Write-Host "Build complete! $zipName is ready for WordPress installation." -ForegroundColor Green
