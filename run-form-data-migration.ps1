# Adds form_data + documents columns to enrollments (required for enrollment wizard submit).
# 1. Open XAMPP Control Panel and click Start on MySQL (must show green/running).
# 2. Run:  cd backend; .\run-form-data-migration.ps1

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

function Test-MySqlPort {
    return (Test-NetConnection -ComputerName 127.0.0.1 -Port 3306 -WarningAction SilentlyContinue).TcpTestSucceeded
}

Write-Host 'Checking MySQL on 127.0.0.1:3306...' -ForegroundColor Cyan

if (-not (Test-MySqlPort)) {
    Write-Host ''
    Write-Host 'MySQL is not running.' -ForegroundColor Red
    Write-Host 'Please open XAMPP Control Panel and click Start on MySQL, then run this script again.'
    Write-Host ''
    exit 1
}

Write-Host 'MySQL is up. Running migration...' -ForegroundColor Green

$migrateOk = $true
php artisan migrate --path=database/migrations/2026_06_14_180000_add_form_data_to_enrollments_table.php --force
if ($LASTEXITCODE -ne 0) {
  $migrateOk = $false
  Write-Host ''
  Write-Host 'Artisan migrate failed. Trying direct SQL via mysql client...' -ForegroundColor Yellow
  $mysqlExe = 'C:\xampp\mysql\bin\mysql.exe'
  if (Test-Path $mysqlExe) {
    & $mysqlExe -h 127.0.0.1 -u root eerc_lms -e "ALTER TABLE enrollments ADD COLUMN form_data JSON NULL AFTER payment_proof_mime;"
    & $mysqlExe -h 127.0.0.1 -u root eerc_lms -e "ALTER TABLE enrollments ADD COLUMN documents JSON NULL AFTER form_data;"
    if ($LASTEXITCODE -eq 0) {
      $migrateOk = $true
      Write-Host 'Columns added via mysql client.' -ForegroundColor Green
    }
  }
}

if (-not $migrateOk) {
  Write-Host ''
  Write-Host 'Automatic fix failed. Run this SQL in phpMyAdmin (database: eerc_lms):' -ForegroundColor Red
  Write-Host '  backend/database/sql/add_form_data_to_enrollments_mariadb.sql'
  exit 1
}

Write-Host ''
Write-Host 'Done. enrollments.form_data and enrollments.documents are ready.' -ForegroundColor Green
php artisan migrate:status | Select-String 'form_data'
