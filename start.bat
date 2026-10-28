@echo off
rem Starts the Media Requests preview on this computer and opens it in the browser.
cd /d "%~dp0"
start "" http://127.0.0.1:8000
".tools\php\php.exe" artisan serve --host=127.0.0.1 --port=8000
