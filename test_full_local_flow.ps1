$initBody = '{"captcha_token":"test"}'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$initRes = Invoke-RestMethod -Uri "http://localhost/byd-voice-assistant/public/api/init-session" -Method Post -Headers @{"Content-Type"="application/json"} -Body $initBody -WebSession $session

$token = $initRes.access_token
$sessId = $initRes.session_id
$csrf = $initRes.csrf_token

Write-Host "Session ID: $sessId"
Write-Host "CSRF Token: $csrf"

$authHeaders = @{
    "Content-Type" = "application/json"
    "X-CSRF-Token" = $csrf
}
$authBody = '{"gender":"male"}'
$vapiAuthRes = Invoke-RestMethod -Uri "http://localhost/byd-voice-assistant/public/api/vapi-auth" -Method Post -Headers $authHeaders -Body $authBody -WebSession $session

Write-Host "=== SERVER URL IN RESPONSE ===" -ForegroundColor Yellow
Write-Host "Assistant Server URL: '$($vapiAuthRes.assistantConfig.server.url)'"
Write-Host "Tool 0 Server URL:      '$($vapiAuthRes.assistantConfig.model.tools[0].server.url)'"
