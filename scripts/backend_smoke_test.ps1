param(
    [string]$BaseUrl = 'http://127.0.0.1:8082',
    [string]$DbHost = 'localhost',
    [int]$DbPort = 5432,
    [string]$DbName = 'E-bookStore_db',
    [string]$DbUser = 'postgres',
    [string]$DbPassword = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($DbPassword)) {
    throw 'DbPassword is required.'
}

$email = 'smoke_' + [guid]::NewGuid().ToString('N').Substring(0, 8) + '@example.com'
$registerBody = @{
    first_name = 'Smoke'
    last_name = 'Test'
    email = $email
    password = 'SmokePass123'
    phone = '123456'
    address = 'Smoke Address'
} | ConvertTo-Json

$register = Invoke-WebRequest -UseBasicParsing "$BaseUrl/customers/register" -Method Post -ContentType 'application/json' -Body $registerBody
$registerJson = $register.Content | ConvertFrom-Json

$loginBody = @{ email = $email; password = 'SmokePass123' } | ConvertTo-Json
$login = Invoke-WebRequest -UseBasicParsing "$BaseUrl/customers/login" -Method Post -ContentType 'application/json' -Body $loginBody
$loginJson = $login.Content | ConvertFrom-Json

$access = $loginJson.data.access_token
$refresh = $loginJson.data.refresh_token
$customerId = [int]$loginJson.data.user.id

Invoke-WebRequest -UseBasicParsing "$BaseUrl/auth/me" -Headers @{ Authorization = "Bearer $access" } | Out-Null

$refreshReq = @{ refresh_token = $refresh } | ConvertTo-Json
$refreshResp = Invoke-WebRequest -UseBasicParsing "$BaseUrl/auth/refresh" -Method Post -ContentType 'application/json' -Body $refreshReq
$refreshJson = $refreshResp.Content | ConvertFrom-Json

$logoutReq = @{ refresh_token = $refreshJson.data.refresh_token } | ConvertTo-Json
Invoke-WebRequest -UseBasicParsing "$BaseUrl/auth/logout" -Method Post -ContentType 'application/json' -Headers @{ Authorization = "Bearer $($refreshJson.data.access_token)" } -Body $logoutReq | Out-Null

$env:PGPASSWORD = $DbPassword
& psql -h $DbHost -p $DbPort -U $DbUser -d $DbName -c "DELETE FROM password_reset_tokens WHERE customer_id = $customerId; INSERT INTO password_reset_tokens (customer_id, token, expires_at) VALUES ($customerId, '654321', NOW() + INTERVAL '15 minutes');" | Out-Null

$resetBody = @{ token = '654321'; new_password = 'NewSmokePass123' } | ConvertTo-Json
Invoke-WebRequest -UseBasicParsing "$BaseUrl/customers/reset-password" -Method Post -ContentType 'application/json' -Body $resetBody | Out-Null

$reloginBody = @{ email = $email; password = 'NewSmokePass123' } | ConvertTo-Json
Invoke-WebRequest -UseBasicParsing "$BaseUrl/customers/login" -Method Post -ContentType 'application/json' -Body $reloginBody | Out-Null

Write-Host 'Backend smoke test passed.'
