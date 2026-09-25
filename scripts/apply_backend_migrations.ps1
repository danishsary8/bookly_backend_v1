param(
    [string]$HostName = 'localhost',
    [int]$Port = 5432,
    [string]$Database = 'E-bookStore_db',
    [string]$Username = 'postgres',
    [string]$Password = '',
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($Password)) {
    throw 'Password is required. Pass -Password <db password>.'
}

$env:PGPASSWORD = $Password

$migrationFiles = @(
    'LEGACY_SCHEMA_MIGRATION.sql',
    'database\schema_cart_invoices.sql',
    'REFRESH_TOKEN_MIGRATION.sql',
    'SECURITY_MIGRATION.sql'
)

foreach ($migrationFile in $migrationFiles) {
    $fullPath = Join-Path $ProjectRoot $migrationFile
    if (-not (Test-Path $fullPath)) {
        throw "Missing migration file: $fullPath"
    }

    Write-Host "Applying $migrationFile..."
    & psql -h $HostName -p $Port -U $Username -d $Database -f $fullPath
    if ($LASTEXITCODE -ne 0) {
        throw "Migration failed: $migrationFile"
    }
}

Write-Host 'All backend migrations applied successfully.'
