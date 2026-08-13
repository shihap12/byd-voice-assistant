Write-Host "=== TEST WITHOUT NGROK HEADER ===" -ForegroundColor Yellow
try {
    $r1 = Invoke-WebRequest -Uri "https://rudder-headdress-spill.ngrok-free.dev/health" -Method Get -TimeoutSec 5
    Write-Host "Length: $($r1.Content.Length)"
    Write-Host "First 200 chars: $($r1.Content.Substring(0, [Math]::Min(200, $r1.Content.Length)))"
} catch {
    Write-Host "Err: $_"
}

Write-Host ""
Write-Host "=== TEST WITH NGROK HEADER ===" -ForegroundColor Green
try {
    $r2 = Invoke-WebRequest -Uri "https://rudder-headdress-spill.ngrok-free.dev/health" -Headers @{"ngrok-skip-browser-warning"="true"} -Method Get -TimeoutSec 5
    Write-Host "Length: $($r2.Content.Length)"
    Write-Host "First 200 chars: $($r2.Content.Substring(0, [Math]::Min(200, $r2.Content.Length)))"
} catch {
    Write-Host "Err: $_"
}
