param([ValidateSet('x64','ia32')][string]$Arch = 'x64')
$ErrorActionPreference = 'Stop'
$appsRoot = Split-Path $PSScriptRoot -Parent
Push-Location $appsRoot
try {
    & (Join-Path $PSScriptRoot 'prepare-windows.ps1') -Arch $Arch
    & (Join-Path $PSScriptRoot 'prepare-electron.ps1') -Arch $Arch
    $version = if ($Arch -eq 'ia32') { '43.8.13' } else { '45.5.0' }
    if (-not $env:ELECTRON_BUILDER_COMPRESSION_LEVEL) { $env:ELECTRON_BUILDER_COMPRESSION_LEVEL = '1' }
    & node (Join-Path $appsRoot 'node_modules/electron-builder/cli.js') --win portable "--$Arch" "--config.electronVersion=$version" --config.electronDist=.tooling/electron
    if ($LASTEXITCODE -ne 0) { throw 'Windows packaging failed.' }
} finally { Pop-Location }
