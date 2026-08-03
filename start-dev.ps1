# =====================================================
# BYD Voice Assistant - Dev Startup Script
# شغّل هذا الملف مرة واحدة في بداية كل جلسة عمل
# Usage: powershell -ExecutionPolicy Bypass -File start-dev.ps1
# =====================================================

Write-Host "================================================" -ForegroundColor Cyan
Write-Host "  BYD Voice Assistant - Starting Dev Env" -ForegroundColor Cyan  
Write-Host "================================================" -ForegroundColor Cyan

# 1. Kill any old instances
Write-Host "[1/3] Cleaning up..." -ForegroundColor Yellow
Get-Process redis-server -ErrorAction SilentlyContinue | Stop-Process -Force
$oldPids = netstat -ano | findstr ":8080 " | ForEach-Object { ($_ -split '\s+')[-1] } | Where-Object { $_ -match '^\d+$' -and $_ -ne '0' } | Sort-Object -Unique
foreach ($p in $oldPids) { Stop-Process -Id $p -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 2

# 2. Redis - via cmd window (stays open)
Write-Host "[2/3] Starting Redis in cmd window..." -ForegroundColor Yellow
Start-Process cmd.exe -ArgumentList '/K', 'title [BYD] Redis-Server && color 4E && C:\Redis\redis-server.exe --port 6379 --loglevel notice --save "" && echo Redis stopped!' -WindowStyle Normal
Start-Sleep -Seconds 4
$redisPing = C:\Redis\redis-cli.exe ping 2>&1
if ($redisPing -eq "PONG") {
    Write-Host "   Redis: RUNNING" -ForegroundColor Green
} else {
    Write-Host "   Redis FAILED - closing cmd window kills Redis! Leave Redis window OPEN." -ForegroundColor Red
    Write-Host "   Please keep the Redis cmd window open during development." -ForegroundColor Yellow
}

# 3. PHP Backend on :8080 - via cmd window
Write-Host "[3/3] Starting PHP Backend on :8080..." -ForegroundColor Yellow
Start-Process cmd.exe -ArgumentList '/K', 'title [BYD] PHP-Backend-8080 && color 1E && C:\xampp\php\php.exe -S 0.0.0.0:8080 -t C:\xampp\htdocs\byd-voice-assistant\public\' -WindowStyle Normal
Start-Sleep -Seconds 3
if (netstat -ano | findstr ":8080 ") {
    Write-Host "   PHP: RUNNING on http://localhost:8080" -ForegroundColor Green
}

# Queue Worker
Start-Process cmd.exe -ArgumentList '/K', 'title [BYD] Queue-Worker && color 2E && C:\xampp\php\php.exe C:\xampp\htdocs\byd-voice-assistant\workers\queue_worker.php pdf_processing' -WindowStyle Normal

Write-Host ""
Write-Host "================================================" -ForegroundColor Green
Write-Host "  3 windows opened. DO NOT close them!" -ForegroundColor Yellow
Write-Host "  Redis:   CMD Window (Red)" -ForegroundColor Red
Write-Host "  Backend: CMD Window (Blue)" -ForegroundColor Blue
Write-Host "  Worker:  CMD Window (Green)" -ForegroundColor Green
Write-Host "================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Frontend: http://localhost:5173" -ForegroundColor White
Write-Host "Backend:  http://localhost:8080" -ForegroundColor White
