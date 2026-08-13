$res = Invoke-WebRequest -Uri "https://rudder-headdress-spill.ngrok-free.dev/health" -Method Get
Write-Host "StatusCode: $($res.StatusCode)"
Write-Host "Raw Content:"
Write-Host $res.Content
