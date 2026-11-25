param([ValidateSet('x64','ia32')][string]$Arch = 'x64')
$ErrorActionPreference = 'Stop'
$appsRoot = Split-Path $PSScriptRoot -Parent
$version = if ($Arch -eq 'ia32') { '43.8.13' } else { '45.5.0' }
$cache = Join-Path $appsRoot '.tooling/electron'
New-Item -ItemType Directory -Force -Path $cache | Out-Null
$name = "electron-v$version-win32-$Arch.zip"
$archive = Join-Path $cache $name
$checksums = Join-Path $cache "SHASUMS256-v$version.txt"
if (-not (Test-Path $checksums)) {
    $release = Invoke-RestMethod "https://api.github.com/repos/electron/electron/releases/tags/v$version" -TimeoutSec 30
    $asset = $release.assets | Where-Object name -eq 'SHASUMS256.txt'
    Invoke-WebRequest -Uri $asset.url -Headers @{'Accept'='application/octet-stream'} -OutFile $checksums -UseBasicParsing -TimeoutSec 30
}
$line = Get-Content -LiteralPath $checksums | Where-Object { $_.EndsWith($name) }
if (-not $line) { throw "No official checksum found for $name" }
$expected = $line.Substring(0,64)
if ((Test-Path $archive) -and (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -eq $expected) { Write-Output "Verified $name"; return }
$download = Join-Path $cache "$name.download"
Invoke-WebRequest -Uri "https://npmmirror.com/mirrors/electron/$version/$name" -OutFile $download -UseBasicParsing -TimeoutSec 600
if ((Get-FileHash -LiteralPath $download -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expected) { throw 'Electron download did not match its official release checksum.' }
Move-Item -LiteralPath $download -Destination $archive -Force
Write-Output "Downloaded and verified $name"
