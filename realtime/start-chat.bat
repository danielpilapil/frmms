@echo off
cd /d "%~dp0"
echo Starting FleetGo realtime chat server on port 3001...
node server.js
pause
