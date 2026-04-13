$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$backupFile = Join-Path $projectRoot 'var\backups\pidev1_backup_2026-04-08.sql'
$mysql = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe'
$mysqlAdmin = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqladmin.exe'

if (-not (Test-Path $mysql)) {
    throw "Client MySQL introuvable: $mysql"
}

if (-not (Test-Path $backupFile)) {
    throw "Sauvegarde introuvable: $backupFile"
}

& $mysqlAdmin --protocol=tcp --host=127.0.0.1 --port=3307 -u root ping | Out-Null

& $mysql --protocol=tcp --host=127.0.0.1 --port=3307 -u root -e "CREATE DATABASE IF NOT EXISTS pidev1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

Get-Content $backupFile | & $mysql --protocol=tcp --host=127.0.0.1 --port=3307 -u root pidev1

& $mysql --protocol=tcp --host=127.0.0.1 --port=3307 -u root -e "SELECT VERSION() AS version; SELECT COUNT(*) AS tables_count FROM information_schema.tables WHERE table_schema='pidev1';"
