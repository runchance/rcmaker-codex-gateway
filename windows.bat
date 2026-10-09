@echo off
setlocal EnableExtensions
chcp 65001 >nul

cd /d "%~dp0"

set "PHP_BIN=php"
if exist "%~dp0php.exe" set "PHP_BIN=%~dp0php.exe"
"%PHP_BIN%" index.php start

pause
endlocal
