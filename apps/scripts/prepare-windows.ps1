param([ValidateSet('x64','ia32')][string]$Arch = 'x64')
$ErrorActionPreference = 'Stop'
$appsRoot = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $appsRoot -Parent
$toolRoot = Join-Path $appsRoot '.tooling'
$buildRoot = Join-Path $appsRoot 'build'
New-Item -ItemType Directory -Force -Path $toolRoot,$buildRoot | Out-Null
$phpArch = if ($Arch -eq 'ia32') { 'x86' } else { 'x64' }
$phpHash = if ($Arch -eq 'ia32') { '3db0d8ec1618b06b20470c438e009836614cb35533d92b6cec048fcdaddc7a0a' } else { '4ae6c72bc0ca4f6b8f3e9b107874bae0135699f3aabe6c26a1f6103d040eea97' }
$phpRoot = Join-Path $toolRoot "php-$Arch"
if (-not (Test-Path (Join-Path $phpRoot 'php.exe'))) {
    $archive = Join-Path $toolRoot "php-8.5.13-$phpArch.zip"
    if (-not (Test-Path $archive)) { Invoke-WebRequest -Uri "https://downloads.php.net/~windows/releases/php-8.5.13-nts-Win32-vs17-$phpArch.zip" -OutFile $archive -UseBasicParsing }
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $phpHash) { throw 'PHP archive checksum mismatch.' }
    Expand-Archive -LiteralPath $archive -DestinationPath $phpRoot -Force
}
$phpIni = @'
[PHP]
extension_dir="ext"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
memory_limit=256M
upload_max_filesize=6M
post_max_size=8M
max_execution_time=60
date.timezone=UTC
display_errors=Off
log_errors=On
expose_php=Off
opcache.enable=0
opcache.enable_cli=0
'@
[IO.File]::WriteAllText((Join-Path $phpRoot 'php.ini'), $phpIni, [Text.UTF8Encoding]::new($false))
$crtRoot = Join-Path $env:WINDIR $(if ($Arch -eq 'ia32' -and [Environment]::Is64BitOperatingSystem) { 'SysWOW64' } else { 'System32' })
foreach ($library in @('vcruntime140.dll','vcruntime140_1.dll','vcruntime140_threads.dll','msvcp140.dll','msvcp140_1.dll','msvcp140_2.dll','msvcp140_atomic_wait.dll','msvcp140_codecvt_ids.dll','concrt140.dll')) {
    $runtime = Join-Path $crtRoot $library
    if (Test-Path $runtime) { Copy-Item -LiteralPath $runtime -Destination $phpRoot -Force }
}
if (-not (Test-Path (Join-Path $phpRoot 'vcruntime140.dll'))) { throw 'Install the Microsoft Visual C++ v14 runtime on the build machine before preparing the portable PHP runtime.' }
$backendTarget = Join-Path $buildRoot 'backend'
New-Item -ItemType Directory -Force -Path $backendTarget | Out-Null
foreach ($name in @('app','bootstrap','config','database','public','routes','resources')) {
    $source = Join-Path (Join-Path $repoRoot 'backend') $name
    $target = Join-Path $backendTarget $name
    & robocopy $source $target /E /NFL /NDL /NJH /NJS /NP /XF '*.sqlite' '*.sqlite-*' '.env' /XD 'node_modules' 'browser-test' | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "Failed to copy backend $name" }
}
foreach ($name in @('artisan','composer.json','composer.lock')) { Copy-Item -LiteralPath (Join-Path (Join-Path $repoRoot 'backend') $name) -Destination $backendTarget -Force }
Get-ChildItem (Join-Path $backendTarget 'bootstrap/cache') -File -Filter '*.php' -ErrorAction SilentlyContinue | Remove-Item
foreach ($name in @('storage/app/private','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs')) { New-Item -ItemType Directory -Force -Path (Join-Path $backendTarget $name) | Out-Null }
Push-Location $backendTarget
try { & composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader; if ($LASTEXITCODE -ne 0) { throw 'Production Composer install failed.' } } finally { Pop-Location }
$realtimeTarget = Join-Path $buildRoot 'realtime'
New-Item -ItemType Directory -Force -Path $realtimeTarget | Out-Null
Copy-Item -LiteralPath (Join-Path $repoRoot 'realtime/server.mjs') -Destination $realtimeTarget -Force
Copy-Item -LiteralPath (Join-Path $repoRoot 'realtime/package.json') -Destination $realtimeTarget -Force
$wsTarget = Join-Path $realtimeTarget 'node_modules/ws'
New-Item -ItemType Directory -Force -Path $wsTarget | Out-Null
Copy-Item -Path (Join-Path $repoRoot 'realtime/node_modules/ws/*') -Destination $wsTarget -Recurse -Force
Write-Output "Local backend and PHP $phpArch runtime prepared."
