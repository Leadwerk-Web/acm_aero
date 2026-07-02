<#
.SYNOPSIS
    Kopiert Leadwerk-Bundle (3 Plugins + Theme) von diesem Repo in eine WordPress-Installation.

.PARAMETER WordPressRoot
    Pfad zum WordPress-Root (Ordner mit wp-config.php und wp-content).

.EXAMPLE
    .\scripts\copy-leadwerk-bundle-to-wp.ps1 -WordPressRoot "C:\xampp\htdocs\meine-site"

Hinweise nach dem Kopieren:
    0. PHP-Extensions json, dom, libxml aktiv; DB-Benutzer mit CREATE TABLE (WPML Clone Tabellen).
    1. Plugins aktivieren in Reihenfolge: leadwerk-wpml-clone -> leadwerk-fields -> leadwerk_importer
    2. Advanced Custom Fields (Free/Pro) deaktivieren; kein echtes WPML parallel zu Leadwerk WPML Clone.
    3. Theme "Leadwerk Theme - ACM" aktivieren.
    4. Tools -> Leadwerk Import: zuerst Dry-Run, dann Live-Import.
    5. Nach Live-Import: Einstellungen -> Permalinks speichern; Lesen -> Startseite pruefen;
       Smoke-Tests: /, /news/, /news/{slug}/, /en/
#>
param(
    [Parameter(Mandatory = $true)]
    [string] $WordPressRoot
)

$ErrorActionPreference = "Stop"
$WordPressRoot = $WordPressRoot.TrimEnd("\", "/")
$bundleRoot = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path (Join-Path $bundleRoot "leadwerk_importer\leadwerk-importer.php"))) {
    $bundleRoot = (Get-Location).Path
}

$wpContent = Join-Path $WordPressRoot "wp-content"
if (-not (Test-Path $wpContent)) {
    Write-Error "wp-content nicht gefunden unter: $WordPressRoot"
}

$pluginsDest = Join-Path $wpContent "plugins"
$themesDest = Join-Path $wpContent "themes"

foreach ($name in @("leadwerk-fields", "leadwerk-wpml-clone", "leadwerk_importer")) {
    $src = Join-Path $bundleRoot $name
    $dst = Join-Path $pluginsDest $name
    if (-not (Test-Path $src)) {
        Write-Error "Quelle fehlt: $src"
    }
    if (Test-Path $dst) {
        Remove-Item -Recurse -Force $dst
    }
    Copy-Item -Recurse -Force $src $dst
    Write-Host "OK: $name -> $dst" -ForegroundColor Green
}

$themeSrc = Join-Path $bundleRoot "leadwerk_theme"
$themeDst = Join-Path $themesDest "leadwerk_theme"
if (-not (Test-Path $themeSrc)) {
    Write-Error "Quelle fehlt: $themeSrc"
}
if (Test-Path $themeDst) {
    Remove-Item -Recurse -Force $themeDst
}
Copy-Item -Recurse -Force $themeSrc $themeDst
Write-Host "OK: leadwerk_theme -> $themeDst" -ForegroundColor Green

Write-Host ""
Write-Host "Naechste Schritte: WPML Clone -> Leadwerk Fields -> Leadwerk Importer aktivieren; ACF aus; Theme aktivieren; Dry-Run dann Import; Permalinks speichern." -ForegroundColor Cyan
