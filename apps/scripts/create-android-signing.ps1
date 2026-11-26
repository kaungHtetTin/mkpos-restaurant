$ErrorActionPreference = 'Stop'
$appsRoot = Split-Path $PSScriptRoot -Parent
$signing = Join-Path $appsRoot '.signing'
$properties = Join-Path $signing 'android-release.properties'
$keystore = Join-Path $signing 'mkpos-restaurant-release.jks'
if ((Test-Path -LiteralPath $properties) -or (Test-Path -LiteralPath $keystore)) { throw 'A release signing identity already exists. Preserve it for future releases.' }
$jdk = Get-ChildItem (Join-Path $appsRoot '.tooling') -Directory -Filter 'jdk-21*' | Select-Object -First 1
if (-not $jdk) { throw 'Install Java 21 before generating the signing key.' }
New-Item -ItemType Directory -Force -Path $signing | Out-Null
$bytes = New-Object byte[] 32
$random = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $random.GetBytes($bytes) } finally { $random.Dispose() }
$password = ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
$env:MKPOS_KEYSTORE_PASSWORD = $password
try {
    & (Join-Path $jdk.FullName 'bin/keytool.exe') -genkeypair -keystore $keystore -storetype JKS -alias mkpos-restaurant -storepass:env MKPOS_KEYSTORE_PASSWORD -keypass:env MKPOS_KEYSTORE_PASSWORD -keyalg RSA -keysize 3072 -validity 10000 -dname 'CN=MKPOS restaurant, O=MKPOS, C=MM'
    if ($LASTEXITCODE -ne 0) { throw 'Release signing key creation failed.' }
    [IO.File]::WriteAllText($properties, "storePassword=$password`nkeyPassword=$password`nkeyAlias=mkpos-restaurant`n", [Text.UTF8Encoding]::new($false))
    Write-Output 'Android release identity created in apps/.signing. Keep this private directory backed up for future updates.'
} finally { Remove-Item Env:MKPOS_KEYSTORE_PASSWORD -ErrorAction SilentlyContinue }
