@echo off
REM K-one WMS - Complete Setup & Execution Script (Windows)
REM Run this script to get everything working TODAY

echo.
echo ============================================
echo   K-one WMS - Complete Setup ^& Test
echo ============================================
echo.

REM Step 1: Check if node and npm are installed
echo Step 1: Verifying prerequisites...
where node >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo Error: Node.js not found. Please install Node.js first.
    exit /b 1
)
where npm >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo Error: npm not found. Please install npm first.
    exit /b 1
)
echo [OK] Node and npm found
echo.

REM Step 2: Install dependencies
echo Step 2: Installing dependencies...
cd /d "%~dp0frontend"
call npm install --legacy-peer-deps
if %ERRORLEVEL% NEQ 0 (
    echo Error: Failed to install dependencies
    exit /b 1
)
echo [OK] Dependencies installed
echo.

REM Step 3: Start Vite dev server
echo Step 3: Starting Vite dev server...
echo.
echo IMPORTANT:
echo   1. Wait for the message: "Local: http://localhost:5173/"
echo   2. Open that URL in your browser
echo   3. Login with admin / admin123
echo   4. Follow the testing checklist in START-HERE-TODAY.md
echo.
echo Starting Vite...
echo.

call npm run dev

pause
