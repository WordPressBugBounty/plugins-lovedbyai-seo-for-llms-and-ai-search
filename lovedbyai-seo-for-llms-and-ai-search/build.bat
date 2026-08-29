@echo off
REM Windows launcher for plugin build. Runs build.ps1 from the script directory.
setlocal
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build.ps1"
exit /b %ERRORLEVEL%
