#Requires -Version 5.1
<#
.SYNOPSIS
  Build jisento-migration.zip from HEAD with only release files.

.DESCRIPTION
  Archives the current HEAD commit into dist/jisento-migration.zip with a top-level
  folder named jisento-migration. Paths listed in .distignore (or bin/distignore) are excluded.
#>
[CmdletBinding()]
param(
    [string]$OutputDir = ''
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path (Join-Path $Root 'jisento-migration.php'))) {
    throw 'Plugin root not found (missing jisento-migration.php).'
}

if (-not $OutputDir) {
    $OutputDir = Join-Path $Root 'dist'
}
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$ignoreFile = Join-Path $Root '.distignore'
if (-not (Test-Path $ignoreFile)) {
    $ignoreFile = Join-Path $Root 'bin/distignore'
}
$patterns = @()
if (Test-Path $ignoreFile) {
    $patterns = Get-Content $ignoreFile | ForEach-Object { $_.Trim() } | Where-Object {
        $_ -and -not $_.StartsWith('#')
    }
}

function Test-Ignored([string]$RelPath) {
    # Do not use TrimStart('./') — in PowerShell that strips every leading '.' or '/',
    # which breaks ignore patterns like .distignore and .wordpress-org/.
    $norm = $RelPath.Replace('\', '/')
    while ($norm.StartsWith('./')) { $norm = $norm.Substring(2) }
    foreach ($pat in $script:patterns) {
        $p = $pat.Replace('\', '/')
        if ($p.StartsWith('/')) { $p = $p.Substring(1) }
        if ($p.StartsWith('*')) {
            $suffix = $p.Substring(1)
            if ($suffix -and $norm.EndsWith($suffix)) { return $true }
            continue
        }
        if ($norm -eq $p) { return $true }
        if ($norm.StartsWith($p.TrimEnd('/') + '/')) { return $true }
        if ($p -notmatch '/' -and ($norm -eq $p -or $norm.StartsWith($p + '/') -or $norm.Contains('/' + $p + '/'))) {
            return $true
        }
    }
    return $false
}

$stage = Join-Path $env:TEMP ('jisento-build-' + [guid]::NewGuid().ToString('N'))
$pluginStage = Join-Path $stage 'jisento-migration'
New-Item -ItemType Directory -Force -Path $pluginStage | Out-Null

try {
    $files = @(git -C $Root ls-files)
    if (-not $files -or $LASTEXITCODE -ne 0) {
        throw 'git ls-files failed; build from HEAD requires a git checkout.'
    }
    foreach ($rel in $files) {
        if (Test-Ignored $rel) { continue }
        $src = Join-Path $Root $rel
        if (-not (Test-Path -LiteralPath $src)) { continue }
        $dest = Join-Path $pluginStage $rel
        $dir = Split-Path $dest -Parent
        if (-not (Test-Path -LiteralPath $dir)) {
            New-Item -ItemType Directory -Force -Path $dir | Out-Null
        }
        Copy-Item -LiteralPath $src -Destination $dest -Force
    }

    $zip = Join-Path $OutputDir 'jisento-migration.zip'
    if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }

    # Compress-Archive embeds Windows backslashes; WordPress/Linux need '/'.
    # Use FullName (not Resolve-Path) so short/long 8.3 paths stay consistent.
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zipStream = [System.IO.Compression.ZipFile]::Open($zip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $pluginStageFull = (Get-Item -LiteralPath $pluginStage).FullName.TrimEnd('\')
        Get-ChildItem -LiteralPath $pluginStage -Recurse -File | ForEach-Object {
            $full = $_.FullName
            $rest = $full.Substring($pluginStageFull.Length)
            $inner = ($rest -replace '^\\+', '') -replace '\\', '/'
            $entry = 'jisento-migration/' + $inner
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zipStream,
                $full,
                $entry,
                [System.IO.Compression.CompressionLevel]::Optimal
            )
        }
    } finally {
        $zipStream.Dispose()
    }

    Write-Host "Built $zip"
    Write-Host ('Size: {0:N0} bytes' -f (Get-Item -LiteralPath $zip).Length)
} finally {
    Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue
}
