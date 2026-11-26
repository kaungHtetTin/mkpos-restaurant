$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$appsRoot = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $appsRoot -Parent
$source = [Drawing.Image]::FromFile((Join-Path $repoRoot 'frontend/public/brand/platform-light.png'))
$branding = Join-Path $appsRoot 'branding'
New-Item -ItemType Directory -Force -Path $branding | Out-Null
function Save-Logo([string]$Path, [int]$Width, [int]$Height, [double]$Scale = 1) {
    $bitmap = [Drawing.Bitmap]::new($Width, $Height)
    $graphics = [Drawing.Graphics]::FromImage($bitmap)
    try {
        $graphics.Clear([Drawing.Color]::White)
        $graphics.InterpolationMode = [Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $size = [int]([Math]::Min($Width, $Height) * $Scale)
        $graphics.DrawImage($source, [Drawing.Rectangle]::new([int](($Width-$size)/2), [int](($Height-$size)/2), $size, $size))
        $bitmap.Save($Path, [Drawing.Imaging.ImageFormat]::Png)
    } finally { $graphics.Dispose(); $bitmap.Dispose() }
}
try {
    Save-Logo (Join-Path $appsRoot 'shell/icon.png') 512 512
    $sizes = @(16, 24, 32, 48, 64, 128, 256)
    $frames = @()
    foreach ($size in $sizes) {
        $png = Join-Path $branding "icon-$size.png"
        Save-Logo $png $size $size
        $frames += ,([IO.File]::ReadAllBytes($png))
    }
    $stream = [IO.File]::Create((Join-Path $branding 'app.ico'))
    $writer = [IO.BinaryWriter]::new($stream)
    try {
        $writer.Write([uint16]0); $writer.Write([uint16]1); $writer.Write([uint16]$sizes.Count)
        $offset = 6 + 16 * $sizes.Count
        for ($index = 0; $index -lt $sizes.Count; $index++) {
            $sizeByte = if ($sizes[$index] -eq 256) { 0 } else { $sizes[$index] }
            $writer.Write([byte]$sizeByte); $writer.Write([byte]$sizeByte)
            $writer.Write([byte]0); $writer.Write([byte]0); $writer.Write([uint16]1); $writer.Write([uint16]32)
            $writer.Write([uint32]$frames[$index].Length); $writer.Write([uint32]$offset)
            $offset += $frames[$index].Length
        }
        foreach ($frame in $frames) { $writer.Write([byte[]]$frame) }
    } finally { $writer.Dispose(); $stream.Dispose() }
    $res = Join-Path $appsRoot 'android/app/src/main/res'
    $densities = @{ mdpi = 1; hdpi = 1.5; xhdpi = 2; xxhdpi = 3; xxxhdpi = 4 }
    foreach ($density in $densities.Keys) {
        $directory = Join-Path $res "mipmap-$density"
        Save-Logo (Join-Path $directory 'ic_launcher.png') ([int](48*$densities[$density])) ([int](48*$densities[$density]))
        Save-Logo (Join-Path $directory 'ic_launcher_round.png') ([int](48*$densities[$density])) ([int](48*$densities[$density]))
        Save-Logo (Join-Path $directory 'ic_launcher_foreground.png') ([int](108*$densities[$density])) ([int](108*$densities[$density])) 0.85
    }
    foreach ($splash in Get-ChildItem -LiteralPath $res -Recurse -File -Filter 'splash.png') {
        $image = [Drawing.Image]::FromFile($splash.FullName)
        $width = $image.Width; $height = $image.Height; $image.Dispose()
        Save-Logo $splash.FullName $width $height 0.45
    }
    Write-Output 'MKPOS restaurant Windows, Android and connection-screen icons prepared.'
} finally { $source.Dispose() }
