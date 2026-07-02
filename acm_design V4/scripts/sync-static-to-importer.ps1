# Kopiert die statische ACM-Site (ohne leadwerk_*, node_modules) nach leadwerk_importer/source_assets.
# Der Importer liest ausschliesslich source_assets (Filter leadwerk_import_source_root).
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Dest = Join-Path $Root "leadwerk_importer\source_assets"

New-Item -ItemType Directory -Force -Path $Dest | Out-Null

Get-ChildItem -Path $Root -Filter "*.html" -File | Copy-Item -Destination $Dest -Force
foreach ($f in @("nav-active.js", "mobile-qa.css", "robots.txt", "page-sitemap.xml", "package.json")) {
    $p = Join-Path $Root $f
    if (Test-Path $p) { Copy-Item $p $Dest -Force }
}

$NewsSrc = Join-Path $Root "news"
$NewsDst = Join-Path $Dest "news"
if (Test-Path $NewsSrc) {
    Remove-Item $NewsDst -Recurse -Force -ErrorAction SilentlyContinue
    Copy-Item $NewsSrc $NewsDst -Recurse -Force
} else {
    if (Test-Path $NewsDst) {
        Remove-Item $NewsDst -Recurse -Force -ErrorAction SilentlyContinue
    }
    Write-Warning "Kein Ordner '$NewsSrc': leadwerk_importer/source_assets/news wurde entfernt, damit keine veralteten Artikel-HTMLs liegen bleiben."
}

$FotosSrc = Join-Path $Root "Fotos"
$FotosDst = Join-Path $Dest "Fotos"
if (Test-Path $FotosSrc) {
    Remove-Item $FotosDst -Recurse -Force -ErrorAction SilentlyContinue
    Copy-Item $FotosSrc $FotosDst -Recurse -Force
}

# Statische assets/ (z. B. Logos fuer fill_options im Importer)
$AssetsSrc = Join-Path $Root "assets"
$AssetsDst = Join-Path $Dest "assets"
if (Test-Path $AssetsSrc) {
    Remove-Item $AssetsDst -Recurse -Force -ErrorAction SilentlyContinue
    Copy-Item $AssetsSrc $AssetsDst -Recurse -Force
}

$logo = Join-Path $Root "logo.webp"
if (Test-Path $logo) { Copy-Item $logo $Dest -Force }

Write-Host "Synced to $Dest"
