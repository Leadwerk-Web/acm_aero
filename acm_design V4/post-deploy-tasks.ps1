# ACM Post-Deploy Setup Script
# Run this from the root of the acm_design V4 directory (or adapt paths if moved to wp-content)

Write-Host "Starting post-deploy tasks..." -ForegroundColor Cyan

# 1. Download Font Files
$fontsDir = ".\leadwerk_theme\assets\fonts"
if (-not (Test-Path $fontsDir)) {
    New-Item -ItemType Directory -Path $fontsDir | Out-Null
}

$fonts = @{
    "inter-v13-latin-regular.woff2" = "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuLyfMZhrib2Bg-4.woff2";
    "cormorant-garamond-v16-latin-regular.woff2" = "https://fonts.gstatic.com/s/cormorantgaramond/v16/co3bmX5slCNuHLi8bLeY9MK7whWMhyjYpntPqA.woff2";
    "cormorant-garamond-v16-latin-300.woff2" = "https://fonts.gstatic.com/s/cormorantgaramond/v16/co3bmX5slCNuHLi8bLeY9MK7whWMhyjYpntPqA.woff2";
    "cormorant-garamond-v16-latin-500.woff2" = "https://fonts.gstatic.com/s/cormorantgaramond/v16/co3amX5slCNuHLi8bLeY9MK7whWMhyjYoE1fqfO9lQ.woff2";
    "cormorant-garamond-v16-latin-600.woff2" = "https://fonts.gstatic.com/s/cormorantgaramond/v16/co3amX5slCNuHLi8bLeY9MK7whWMhyjYoE1fqfO9lQ.woff2";
    "inter-v13-latin-300.woff2" = "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuLyfMZhrib2Bg-4.woff2";
    "inter-v13-latin-500.woff2" = "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuLyfMZhrib2Bg-4.woff2";
    "inter-v13-latin-600.woff2" = "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuLyfMZhrib2Bg-4.woff2"
}

Write-Host "Downloading Google Fonts locally for GDPR compliance..."
foreach ($font in $fonts.GetEnumerator()) {
    $outPath = Join-Path $fontsDir $font.Key
    if (-not (Test-Path $outPath)) {
        Invoke-WebRequest -Uri $font.Value -OutFile $outPath
        Write-Host "Downloaded $($font.Key)" -ForegroundColor Green
    } else {
        Write-Host "Already exists: $($font.Key)" -ForegroundColor DarkGray
    }
}

Write-Host "Finished." -ForegroundColor Cyan
