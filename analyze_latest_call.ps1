$headers = @{ Authorization = "Bearer 098f1582-db4c-4d9c-9140-2cbf5253e926" }
$result = Invoke-RestMethod -Uri "https://api.vapi.ai/call/019ffa8f-e769-7551-968f-5d615d5bbca9" -Headers $headers -Method Get

# عرض المعلومات المهمة فقط
Write-Host "=== CALL INFO ===" -ForegroundColor Cyan
Write-Host "ID: $($result.id)"
Write-Host "Started: $($result.startedAt)"
Write-Host "Transcript: $($result.transcript)"
Write-Host ""
Write-Host "=== SERVER CONFIG IN TOOLS ===" -ForegroundColor Yellow
$firstTool = $result.assistant.model.tools[0]
Write-Host "Tool 0 server URL: '$($firstTool.server.url)'"
Write-Host ""
Write-Host "=== MESSAGES (tool calls only) ===" -ForegroundColor Green
foreach ($msg in $result.messages) {
    if ($msg.role -eq "tool_calls" -or $msg.role -eq "tool_call_result") {
        Write-Host "[$($msg.role)] $($msg | ConvertTo-Json -Depth 3)"
        Write-Host "---"
    }
}
