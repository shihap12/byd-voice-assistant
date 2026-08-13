Write-Host "Testing http://localhost/health..."
try {
    $resLocal = Invoke-RestMethod -Uri "http://localhost/byd-voice-assistant/public/health" -Method Get -TimeoutSec 3
    Write-Host "Local Apache OK: $($resLocal.status)" -ForegroundColor Green
} catch {
    Write-Host "Local Apache FAIL: $_" -ForegroundColor Red
}

Write-Host "Testing ngrok URL..."
try {
    $resNgrok = Invoke-RestMethod -Uri "https://rudder-headdress-spill.ngrok-free.dev/health" -Method Get -TimeoutSec 4
    Write-Host "ngrok OK: $($resNgrok.status)" -ForegroundColor Green
} catch {
    Write-Host "ngrok FAIL: $_" -ForegroundColor Red
}
