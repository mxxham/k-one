@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM Automated Replenishment Cron Job
REM
REM Schedule via Windows Task Scheduler:
REM   Action:  Start a program
REM   Program:  D:\K-one\RUN-REPLENISHMENT.bat
REM   Trigger:  Every 15 minutes (or as configured)
REM ─────────────────────────────────────────────────────────────────────────

REM Change to project root
cd /d D:\K-one

REM Ensure logs directory exists
if not exist logs mkdir logs

REM Run replenishment cycle and append output to log file
echo. >> logs\replenishment.log
echo ===== %date% %time% ===== >> logs\replenishment.log
php run_replenishment_cycle.php >> logs\replenishment.log 2>&1
