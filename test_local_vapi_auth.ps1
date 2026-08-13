$initBody = '{"captcha_token":"test"}'
$initRes = Invoke-RestMethod -Uri "http://localhost/byd-voice-assistant/public/api/init-session" -Method Post -Headers @{"Content-Type"="application/json"} -Body $initBody

$token = $initRes.access_token
$sessId = $initRes.session_id

Write-Host "Session ID: $sessId"

$authHeaders = @{
    "Content-Type" = "application/json"
    "Authorization" = "Bearer $token"
}
$authBody = '{"gender":"male"}'
$vapiAuthRes = Invoke-RestMethod -Uri "http://localhost/byd-voice-assistant/public/api/vapi-auth" -Method Post -Headers $authHeaders -Body $authBody

$json = $vapiAuthRes | ConvertTo-Json -Depth 10
Write-Host "=== VAPI AUTH RESPONSE ==="
Write-Host $json
