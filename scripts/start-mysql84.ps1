$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$mysqlBase = Join-Path $projectRoot 'var\mysql84'
$mysqlIni = Join-Path $mysqlBase 'my.ini'
$mysqld = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe'
$mysqlAdmin = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqladmin.exe'

if (-not (Test-Path $mysqld)) {
    throw "MySQL 8.4 n'est pas installe a l'emplacement attendu: $mysqld"
}

if (-not (Test-Path $mysqlIni)) {
    throw "Configuration MySQL introuvable: $mysqlIni"
}

$existing = Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host 'MySQL 8 ecoute deja sur le port 3307.'
    exit 0
}

$detachedCommand = 'start "MySQL84" /min "' + $mysqld + '" --defaults-file="' + $mysqlIni + '" --console'
$process = Start-Process cmd.exe -ArgumentList @('/c', $detachedCommand) -WindowStyle Hidden -PassThru

Start-Sleep -Seconds 8

& $mysqlAdmin --protocol=tcp --host=127.0.0.1 --port=3307 -u root ping | Out-Null

Write-Host "MySQL 8 demarre sur 127.0.0.1:3307 (PID lanceur: $($process.Id))."
