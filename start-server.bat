@echo off
rem Land Claim Triangle - LAN game server launcher (Windows)
rem Usage: double-click this file, or: start-server.bat 7000
rem NOTE: keep this .bat file pure ASCII (no Chinese) - cmd.exe parses
rem       batch files in the system ANSI codepage, UTF-8 text here breaks it.
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
  echo [ERROR] "php" was not found on this computer.
  echo.
  echo   Please install PHP 8.0 or newer first, for example:
  echo     winget install PHP.PHP.8.4
  echo   or download the ZIP from https://windows.php.net/download/
  echo   and add its folder to the system PATH.
  echo.
  echo   Details: see README.md in this folder.
  echo.
  pause
  exit /b 1
)

php start.php %*
pause
