try {
    $res = Invoke-WebRequest -Uri "https://rudder-headdress-spill.ngrok-free.dev/health" -Method Get
    Write-Host "Status: $($res.StatusCode)"
    Write-Host "Content preview: $($res.Content.Substring(0, [Math]::Min(300, $res.Content.Length)))"
} catch {
    Write-Host "Err: $_"
}
