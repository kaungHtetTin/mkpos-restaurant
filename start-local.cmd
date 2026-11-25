@echo off
setlocal
title Little Lemon - Local App
cd /d "%~dp0"

set "APP_URL=http://localhost:8002"
set "PHP_BIN=php.exe"
where php.exe >nul 2>&1
if errorlevel 1 (
    set "PHP_BIN=%~dp0..\..\php\php.exe"
    if not exist "%~dp0..\..\php\php.exe" (
        echo PHP was not found. Add PHP to PATH or run this project inside XAMPP's htdocs folder.
        goto :failed
    )
)

if not exist "backend\vendor\autoload.php" (
    echo Backend dependencies are missing. Run composer install inside backend first.
    goto :failed
)
if not exist "backend\.env" (
    echo Backend configuration is missing. Follow the first-time setup in README.md.
    goto :failed
)
where npm.cmd >nul 2>&1
if errorlevel 1 (
    echo Node.js and npm were not found. Install Node.js and reopen this launcher.
    goto :failed
)
if not exist "frontend\node_modules\vite\bin\vite.js" (
    echo Frontend dependencies are missing. Run npm.cmd ci inside frontend first.
    goto :failed
)

if not exist "realtime\node_modules\ws\package.json" (
    echo WebSocket dependencies are missing. Run npm.cmd ci inside realtime first.
    goto :failed
)

if /i "%~1"=="--check" (
    echo Local launch prerequisites are ready.
    exit /b 0
)

echo Building the latest app...
pushd "frontend"
call npm.cmd run build
if errorlevel 1 (
    popd
    echo The frontend build failed. See the error above.
    goto :failed
)
popd

powershell.exe -NoProfile -Command "$ErrorActionPreference='Stop'; try { $health = Invoke-RestMethod -Uri 'http://127.0.0.1:8081/health' -TimeoutSec 2; if ($health.transport -eq 'websocket') { exit 0 } } catch {}; Start-Process -FilePath 'node.exe' -ArgumentList 'server.mjs' -WorkingDirectory (Join-Path (Get-Location) 'realtime') -WindowStyle Hidden -RedirectStandardOutput 'backend/storage/logs/realtime.log' -RedirectStandardError 'backend/storage/logs/realtime-error.log'; for ($attempt=0; $attempt -lt 15; $attempt++) { Start-Sleep -Milliseconds 200; try { $health=Invoke-RestMethod -Uri 'http://127.0.0.1:8081/health' -TimeoutSec 1; if ($health.transport -eq 'websocket') { exit 0 } } catch {} }; exit 1"
if errorlevel 1 (
    echo The WebSocket service could not start. Check backend/storage/logs/realtime-error.log.
    goto :failed
)

powershell.exe -NoProfile -Command "try { $session = Invoke-RestMethod -Uri 'http://localhost:8002/api/v1/session' -TimeoutSec 3; if ($session.csrf) { exit 0 } } catch {}; exit 1" >nul 2>&1
if not errorlevel 1 (
    echo The app is already running at %APP_URL%.
    start "" "%APP_URL%"
    exit /b 0
)

echo.
echo Business app:    %APP_URL%
echo Create account:  %APP_URL%/register
echo Platform office: %APP_URL%/office
echo.
echo Keep this window open. Press Ctrl+C to stop the local server.
echo.

rem Open the browser after the server has had time to start.
start "" /b powershell.exe -NoProfile -WindowStyle Hidden -Command "for ($attempt = 0; $attempt -lt 20; $attempt++) { try { $session = Invoke-RestMethod -Uri 'http://localhost:8002/api/v1/session' -TimeoutSec 1; if ($session.csrf) { Start-Process 'http://localhost:8002'; break } } catch {}; Start-Sleep -Milliseconds 500 }" >nul 2>&1
pushd "backend"
"%PHP_BIN%" artisan serve --host=127.0.0.1 --port=8002 --tries=1
set "SERVER_EXIT=%ERRORLEVEL%"
popd
if not "%SERVER_EXIT%"=="0" (
    echo The local server stopped with an error. Check whether port 8002 is already in use.
    goto :failed
)
exit /b 0

:failed
echo.
pause
exit /b 1
