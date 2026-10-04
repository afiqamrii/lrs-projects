param([ValidateSet('serve','test','admin','demo','build')][string]$Action = 'serve')
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
if (Test-Path -LiteralPath '.tools/php.ini') {
    $env:PHPRC = (Resolve-Path -LiteralPath '.tools/php.ini').Path
}
if ($Action -eq 'build') {
    npm run build
    exit $LASTEXITCODE
}
$modules = php -m
if (-not ($modules -contains 'pdo_pgsql')) {
    throw 'Enable pdo_pgsql for your PHP runtime before running LRS.'
}
switch ($Action) {
    'serve' { php artisan serve --host=127.0.0.1 --port=8000 }
    'test' { php artisan test --compact }
    'admin' { php artisan lrs:admin }
    'demo' { php artisan lrs:demo }
}
exit $LASTEXITCODE
