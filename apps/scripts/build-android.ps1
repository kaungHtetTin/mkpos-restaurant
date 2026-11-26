$ErrorActionPreference = 'Stop'
$appsRoot = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $appsRoot -Parent
$appVersion = (Get-Content -Raw -LiteralPath (Join-Path $appsRoot 'package.json') | ConvertFrom-Json).version
& (Join-Path $PSScriptRoot 'prepare-branding.ps1')
if (-not (Test-Path -LiteralPath (Join-Path $appsRoot '.signing/android-release.properties'))) { throw 'A production signing key is required. Run scripts/create-android-signing.ps1 once, or restore the existing apps/.signing directory.' }
if (-not $env:ANDROID_HOME) { $env:ANDROID_HOME = Join-Path $env:LOCALAPPDATA 'Android/Sdk' }
$jdk = Get-ChildItem (Join-Path $appsRoot '.tooling') -Directory -Filter 'jdk-21*' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($jdk) { $env:JAVA_HOME = $jdk.FullName }
if (-not (Test-Path (Join-Path $env:JAVA_HOME 'bin/java.exe'))) { throw 'Set JAVA_HOME to a Java 21 JDK before building.' }
$sdkPath = $env:ANDROID_HOME.Replace('\','/')
[IO.File]::WriteAllText((Join-Path $appsRoot 'android/local.properties'), "sdk.dir=$sdkPath`n", [Text.UTF8Encoding]::new($false))
Push-Location $appsRoot
try {
    & node (Join-Path $appsRoot 'node_modules/@capacitor/cli/bin/capacitor') sync android
    if ($LASTEXITCODE -ne 0) { throw 'Capacitor sync failed.' }
    Push-Location (Join-Path $appsRoot 'android')
    try { & ./gradlew.bat assembleDebug assembleRelease bundleRelease --no-daemon; if ($LASTEXITCODE -ne 0) { throw 'Android build failed.' } } finally { Pop-Location }
    $output = Join-Path $repoRoot 'artifacts/android'
    New-Item -ItemType Directory -Force -Path $output | Out-Null
    Copy-Item -LiteralPath (Join-Path $appsRoot 'android/app/build/outputs/apk/debug/app-debug.apk') -Destination (Join-Path $output "MKPOS-restaurant-$appVersion-debug.apk") -Force
    Copy-Item -LiteralPath (Join-Path $appsRoot 'android/app/build/outputs/apk/release/app-release.apk') -Destination (Join-Path $output "MKPOS-restaurant-$appVersion-release.apk") -Force
    Copy-Item -LiteralPath (Join-Path $appsRoot 'android/app/build/outputs/bundle/release/app-release.aab') -Destination (Join-Path $output "MKPOS-restaurant-$appVersion-release.aab") -Force
} finally { Pop-Location }
