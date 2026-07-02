# Laedt alle mapping.json-URLs per HTTPS (Invoke-WebRequest) nach _snapshots/myrdbx/html/.
# Nutzen, wenn PHP ohne openssl/curl keine https://-Streams oeffnen kann.
#
# Usage (Repo-Root = acm_design V4):
#   pwsh -File scripts/snapshot-live-fetch.ps1
#   pwsh -File scripts/snapshot-live-fetch.ps1 -BaseUrl "https://b4451i.myrdbx.io"

param(
    [string] $BaseUrl = "https://b4451i.myrdbx.io"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$MappingPath = Join-Path $Root "leadwerk_importer\manifest\mapping.json"
$OutRoot = Join-Path $Root "_snapshots\myrdbx\html"

$BaseUrl = $BaseUrl.TrimEnd("/")

if (-not (Test-Path $MappingPath)) {
    Write-Error "Fehlt: $MappingPath"
}

$mapping = Get-Content -Raw -Encoding UTF8 $MappingPath | ConvertFrom-Json
New-Item -ItemType Directory -Force -Path $OutRoot | Out-Null

$headers = @{
    "User-Agent" = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) ACM-Leadwerk-Snapshot/1.0"
    "Accept"     = "text/html,application/xhtml+xml"
}

$log = New-Object System.Collections.Generic.List[string]

function Save-UrlToRelativePath {
    param([string]$Url, [string]$RelativePath)
    $dest = Join-Path $OutRoot ($RelativePath -replace "/", [IO.Path]::DirectorySeparatorChar)
    $dir = Split-Path -Parent $dest
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    try {
        Invoke-WebRequest -Uri $Url -OutFile $dest -UseBasicParsing -Headers $headers -TimeoutSec 90
        $script:log.Add("OK $RelativePath <= $Url")
    }
    catch {
        $script:log.Add("FAIL $RelativePath <= $Url : $($_.Exception.Message)")
        Set-Content -Path $dest -Value "<!-- fetch failed: $Url -->`n" -Encoding UTF8
    }
}

foreach ($page in $mapping.pages) {
    $rel = [string]$page.source_file
    if ([string]::IsNullOrWhiteSpace($rel)) { continue }
    if ($page.is_front_page) {
        $url = "$BaseUrl/"
    }
    else {
        $slug = [string]$page.slug
        $url = "$BaseUrl/$slug/"
    }
    Save-UrlToRelativePath -Url $url -RelativePath $rel
}

foreach ($article in $mapping.news_articles) {
    $rel = [string]$article.source_file
    if ([string]::IsNullOrWhiteSpace($rel) -or $rel -notmatch "\.html$") { continue }
    $base = [IO.Path]::GetFileNameWithoutExtension($rel)
    $url = "$BaseUrl/news/$base/"
    Save-UrlToRelativePath -Url $url -RelativePath $rel
}

$logPath = Join-Path $Root "_snapshots\myrdbx\fetch-log.txt"
$log | Set-Content -Path $logPath -Encoding UTF8
Write-Host "Fetch abgeschlossen. Log: $logPath"
