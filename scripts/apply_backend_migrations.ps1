param(
    [string]$HostName = 'localhost',
    [int]$Port = 5432,
    [string]$Database = 'E-bookStore_db',
    [string]$Username = 'postgres',
    [string]$Password = '',
    [ValidateSet('Fresh', 'Existing')]
    [string]$Mode = 'Existing',
    [ValidateSet('disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full')]
    [string]$SslMode = 'prefer',
    [ValidateSet('disable', 'prefer', 'require')]
    [string]$ChannelBinding = 'prefer',
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($Password)) {
    throw 'Password is required. Pass -Password <db password>.'
}

$migrationFiles = @()
if ($Mode -eq 'Existing') {
    $migrationFiles += 'LEGACY_SCHEMA_MIGRATION.sql'
}
$migrationFiles += @(
    'database\schema_clean.sql',
    'database\schema_cart_invoices.sql',
    'REFRESH_TOKEN_MIGRATION.sql',
    'SECURITY_MIGRATION.sql'
)

$previousPassword = $env:PGPASSWORD
$previousSslMode = $env:PGSSLMODE
$previousChannelBinding = $env:PGCHANNELBINDING

try {
    $env:PGPASSWORD = $Password
    $env:PGSSLMODE = $SslMode
    $env:PGCHANNELBINDING = $ChannelBinding

    foreach ($migrationFile in $migrationFiles) {
        $fullPath = Join-Path $ProjectRoot $migrationFile
        if (-not (Test-Path $fullPath)) {
            throw "Missing migration file: $fullPath"
        }

        Write-Host "Applying $migrationFile..."
        & psql -v ON_ERROR_STOP=1 -h $HostName -p $Port -U $Username -d $Database -f $fullPath
        if ($LASTEXITCODE -ne 0) {
            throw "Migration failed: $migrationFile"
        }
    }
} finally {
    $env:PGPASSWORD = $previousPassword
    $env:PGSSLMODE = $previousSslMode
    $env:PGCHANNELBINDING = $previousChannelBinding
}

Write-Host 'All backend migrations applied successfully.'
