param([switch]$Quiesced)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$taskWorkspace = (Get-Location).Path
if (-not $Quiesced) { throw 'Use -Quiesced only after stopping writes/workers for a consistent database/private-file backup window. See the Phase 11 runbook.' }
if (Test-Path -LiteralPath 'bootstrap/cache/config.php') { throw 'This local rehearsal requires uncached configuration. Clear only config cache first.' }
$taskEnvironment = @{}
foreach ($taskLine in [IO.File]::ReadAllLines((Join-Path $taskWorkspace '.env'))) {
    if ($taskLine -match '^\s*([A-Z_][A-Z0-9_]*)=(.*)$') { $taskEnvironment[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
}
if ($taskEnvironment['APP_ENV'] -ne 'local' -or $taskEnvironment['DB_CONNECTION'] -ne 'pgsql' -or $taskEnvironment['DB_HOST'] -notin @('127.0.0.1','localhost') -or $taskEnvironment['DB_URL']) { throw 'Loopback local rehearsal only. Choose production backup tooling for the real target.' }
if (-not $taskEnvironment['APP_KEY'] -or $taskEnvironment['DB_DATABASE'] -notmatch '^[a-z][a-z0-9_]{1,50}$') { throw 'Missing application key or unsupported database name.' }
$taskId = (Get-Date).ToUniversalTime().ToString('yyyyMMddHHmmss') + '_' + [Guid]::NewGuid().ToString('N').Substring(0,8)
$taskBackupRoot = [IO.Path]::GetFullPath((Join-Path $taskWorkspace ".tools/backups/$taskId"))
$taskRestoreRoot = [IO.Path]::GetFullPath((Join-Path $taskWorkspace ".tools/restore/$taskId"))
foreach ($taskPath in @($taskBackupRoot,$taskRestoreRoot)) {
    if (-not $taskPath.StartsWith($taskWorkspace + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or (Test-Path -LiteralPath $taskPath)) { throw 'Refusing an existing or out-of-workspace destination.' }
    New-Item -ItemType Directory -Path $taskPath | Out-Null
    $taskSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls.exe $taskPath /inheritance:r /grant:r "*$($taskSid):(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' /q | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not restrict rehearsal directory access.' }
}
$taskPgBin = Join-Path $taskWorkspace '.tools/pgsql/bin'
$taskPgArgs = @('--host',$taskEnvironment['DB_HOST'],'--port',$taskEnvironment['DB_PORT'],'--username',$taskEnvironment['DB_USERNAME'],'--no-password')
$taskTargetDatabase = 'lrs_p11_' + $taskId + '_restore_test'
if ($taskTargetDatabase -notmatch '^lrs_p11_[0-9]{14}_[a-f0-9]{8}_restore_test$' -or $taskTargetDatabase -eq $taskEnvironment['DB_DATABASE']) { throw 'Unsafe restore database identity.' }
$taskOriginalVariables = @{}
foreach ($taskName in @('PGPASSWORD','APP_ENV','APP_DEBUG','APP_KEY','DB_CONNECTION','DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD','DB_DATABASE','DB_URL','LRS_RESTORE_LOCKDOWN','LRS_RESTORE_PRIVATE_ROOT','MAIL_MAILER','MAILBOX_DEMO_ENABLED','OPENAI_API_KEY','PHPRC')) { $taskOriginalVariables[$taskName] = [Environment]::GetEnvironmentVariable($taskName, 'Process') }
$taskStopwatch = [Diagnostics.Stopwatch]::StartNew()
$taskStaging = Join-Path $taskBackupRoot 'staging'
$taskZip = Join-Path $taskBackupRoot 'business-private.zip'
try {
    $env:PGPASSWORD = $taskEnvironment['DB_PASSWORD']
    $env:PHPRC = Join-Path $taskWorkspace '.tools/php.ini'
    New-Item -ItemType Directory -Path $taskStaging | Out-Null
    $taskTables = @('inquiries','clients','vendors','shipment_versions','inquiry_documents','public_submissions','rfqs','rfq_approvals','vendor_offers','offer_selections','client_quotation_revisions','client_quotation_approvals','client_decisions','handoff_revisions','handoff_approvals','handoff_events','mail_messages','mail_dispatches','audit_entries')
    $taskCounts = [ordered]@{}
    foreach ($taskTable in $taskTables) {
        $taskCount = & "$taskPgBin/psql.exe" @taskPgArgs --dbname $taskEnvironment['DB_DATABASE'] --tuples-only --no-align --command "SELECT count(*) FROM $taskTable" 2>&1
        if ($LASTEXITCODE -ne 0) { throw "Could not capture count for $taskTable." }
        $taskCounts[$taskTable] = [int64]("$taskCount".Trim())
    }
    $taskMailSql = "SELECT COALESCE(json_agg(s ORDER BY s.id),'[]'::json) FROM (SELECT id,status,provider_draft_id,provider_sent_id FROM mail_dispatches) s"
    $taskMailJson = & "$taskPgBin/psql.exe" @taskPgArgs --dbname $taskEnvironment['DB_DATABASE'] --tuples-only --no-align --command $taskMailSql 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Could not capture original outbox evidence.' }
    $taskMailStates = "$taskMailJson".Trim() | ConvertFrom-Json -AsHashtable -NoEnumerate
    & "$taskPgBin/pg_dump.exe" @taskPgArgs --format=custom --no-owner --no-acl --file (Join-Path $taskStaging 'business.dump') $taskEnvironment['DB_DATABASE']
    if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL business backup failed.' }
    $taskPrivateSource = Join-Path $taskWorkspace 'storage/app/private'
    $taskFiles = @()
    foreach ($taskFile in Get-ChildItem -LiteralPath $taskPrivateSource -File -Recurse) { $taskFiles += [ordered]@{path=[IO.Path]::GetRelativePath($taskPrivateSource,$taskFile.FullName);size=$taskFile.Length;sha256=(Get-FileHash -LiteralPath $taskFile.FullName -Algorithm SHA256).Hash.ToLowerInvariant()} }
    Copy-Item -LiteralPath $taskPrivateSource -Destination (Join-Path $taskStaging 'private') -Recurse
    $taskManifest = [ordered]@{version='lrs-local-backup-1';captured_at=(Get-Date).ToUniversalTime().ToString('o');source_database=$taskEnvironment['DB_DATABASE'];counts=$taskCounts;mail_states=$taskMailStates;private_files=$taskFiles}
    [IO.File]::WriteAllText((Join-Path $taskStaging 'manifest.json'),($taskManifest | ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false))
    foreach ($taskFile in $taskFiles) {
        $taskCopy = Join-Path (Join-Path $taskStaging 'private') $taskFile.path
        $taskCurrent = Join-Path $taskPrivateSource $taskFile.path
        if ((Get-FileHash -LiteralPath $taskCopy -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskFile.sha256 -or (Get-FileHash -LiteralPath $taskCurrent -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskFile.sha256) { throw 'Private files changed during backup. Repeat during a proper quiet window.' }
    }
    [IO.Compression.ZipFile]::CreateFromDirectory($taskStaging,$taskZip)
    if ((Get-Item -LiteralPath $taskZip).Length -gt 268435456) { throw 'Local archive exceeds 256 MiB. Use streaming production backup tooling.' }
    $taskPlain = [IO.File]::ReadAllBytes($taskZip)
    $taskKey = [Security.Cryptography.RandomNumberGenerator]::GetBytes(32)
    $taskNonce = [Security.Cryptography.RandomNumberGenerator]::GetBytes(12)
    $taskTag = [byte[]]::new(16)
    $taskCipher = [byte[]]::new($taskPlain.Length)
    $taskAad = [Text.Encoding]::UTF8.GetBytes('LRS local backup v1')
    $taskAes = [Security.Cryptography.AesGcm]::new($taskKey,16)
    $taskAes.Encrypt($taskNonce,$taskPlain,$taskCipher,$taskTag,$taskAad)
    $taskAes.Dispose()
    [IO.File]::WriteAllBytes((Join-Path $taskBackupRoot 'business-private.aesgcm'),[byte[]]($taskNonce+$taskTag+$taskCipher))
    [IO.File]::WriteAllBytes((Join-Path $taskBackupRoot 'backup-key.dpapi'),[Security.Cryptography.ProtectedData]::Protect($taskKey,$taskAad,[Security.Cryptography.DataProtectionScope]::CurrentUser))
    [IO.File]::WriteAllBytes((Join-Path $taskBackupRoot 'configuration.dpapi'),[Security.Cryptography.ProtectedData]::Protect([IO.File]::ReadAllBytes((Join-Path $taskWorkspace '.env')),$taskAad,[Security.Cryptography.DataProtectionScope]::CurrentUser))
    $taskBackupMs = $taskStopwatch.ElapsedMilliseconds
    $taskBundle = [IO.File]::ReadAllBytes((Join-Path $taskBackupRoot 'business-private.aesgcm'))
    $taskRecoveredKey = [Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes((Join-Path $taskBackupRoot 'backup-key.dpapi')),$taskAad,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    $taskRecoveredPlain = [byte[]]::new($taskBundle.Length-28)
    $taskAes = [Security.Cryptography.AesGcm]::new($taskRecoveredKey,16)
    $taskAes.Decrypt([byte[]]$taskBundle[0..11],[byte[]]$taskBundle[28..($taskBundle.Length-1)],[byte[]]$taskBundle[12..27],$taskRecoveredPlain,$taskAad)
    $taskAes.Dispose()
    $taskRestoreZip = Join-Path $taskRestoreRoot 'restore.zip'
    [IO.File]::WriteAllBytes($taskRestoreZip,$taskRecoveredPlain)
    [IO.Compression.ZipFile]::ExtractToDirectory($taskRestoreZip,$taskRestoreRoot)
    foreach ($taskFile in $taskFiles) {
        $taskRestoredPath = Join-Path (Join-Path $taskRestoreRoot 'private') $taskFile.path
        if ((Get-FileHash -LiteralPath $taskRestoredPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskFile.sha256) { throw 'Restored private-file checksum differs.' }
    }
    $taskExists = & "$taskPgBin/psql.exe" @taskPgArgs --dbname postgres --tuples-only --no-align --command "SELECT count(*) FROM pg_database WHERE datname='$taskTargetDatabase'" 2>&1
    if ($LASTEXITCODE -ne 0 -or "$taskExists".Trim() -ne '0') { throw 'Destination database exists or cannot be checked. No overwrite.' }
    & "$taskPgBin/createdb.exe" @taskPgArgs --maintenance-db=postgres --template=template0 $taskTargetDatabase
    if ($LASTEXITCODE -ne 0) { throw 'Cannot create isolated database; source unchanged.' }
    & "$taskPgBin/pg_restore.exe" @taskPgArgs --dbname $taskTargetDatabase --no-owner --no-acl --exit-on-error (Join-Path $taskRestoreRoot 'business.dump')
    if ($LASTEXITCODE -ne 0) { throw 'Isolated restore failed. Retain its identity for investigation.' }
    & "$taskPgBin/psql.exe" @taskPgArgs --dbname $taskTargetDatabase --command 'ANALYZE' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Restored planner statistics could not be refreshed.' }
    $taskRecoveredConfig = [Text.Encoding]::UTF8.GetString([Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes((Join-Path $taskBackupRoot 'configuration.dpapi')),$taskAad,[Security.Cryptography.DataProtectionScope]::CurrentUser))
    if ($taskRecoveredConfig -notmatch '(?m)^APP_KEY=(.+)$') { throw 'Protected application key missing.' }
    $env:APP_KEY=$Matches[1].Trim().Trim('"').Trim("'")
    $env:APP_ENV='local'; $env:APP_DEBUG='false'; $env:DB_CONNECTION='pgsql'; $env:DB_HOST=$taskEnvironment['DB_HOST']; $env:DB_PORT=$taskEnvironment['DB_PORT']; $env:DB_USERNAME=$taskEnvironment['DB_USERNAME']; $env:DB_PASSWORD=$taskEnvironment['DB_PASSWORD']; $env:DB_DATABASE=$taskTargetDatabase; $env:DB_URL=''
    $env:LRS_RESTORE_LOCKDOWN='true'; $env:LRS_RESTORE_PRIVATE_ROOT=Join-Path $taskRestoreRoot 'private'; $env:MAIL_MAILER='array'; $env:MAILBOX_DEMO_ENABLED='false'; $env:OPENAI_API_KEY=''
    $taskVerifyOutput = & php artisan lrs:verify-restored-records --manifest (Join-Path $taskRestoreRoot 'manifest.json') --no-interaction 2>&1
    $taskVerifyExit = $LASTEXITCODE
    [IO.File]::WriteAllText((Join-Path $taskRestoreRoot 'verification.json'),($taskVerifyOutput -join [Environment]::NewLine),[Text.UTF8Encoding]::new($false))
    foreach ($taskTable in $taskTables) {
        $taskAfterCount = & "$taskPgBin/psql.exe" @taskPgArgs --dbname $taskEnvironment['DB_DATABASE'] --tuples-only --no-align --command "SELECT count(*) FROM $taskTable" 2>&1
        if ($LASTEXITCODE -ne 0 -or [int64]("$taskAfterCount".Trim()) -ne $taskCounts[$taskTable]) { throw 'Source counts changed during the restore drill.' }
    }
    $taskAfterMail = & "$taskPgBin/psql.exe" @taskPgArgs --dbname $taskEnvironment['DB_DATABASE'] --tuples-only --no-align --command $taskMailSql 2>&1
    if ($LASTEXITCODE -ne 0 -or "$taskAfterMail".Trim() -ne "$taskMailJson".Trim()) { throw 'Source outbox evidence changed during the restore drill.' }
    foreach ($taskFile in $taskFiles) {
        if ((Get-FileHash -LiteralPath (Join-Path $taskPrivateSource $taskFile.path) -Algorithm SHA256).Hash.ToLowerInvariant() -ne $taskFile.sha256) { throw 'Source private artifacts changed during the restore drill.' }
    }
    $taskEvidence = [ordered]@{backup_directory=$taskBackupRoot;restore_directory=$taskRestoreRoot;restore_database=$taskTargetDatabase;private_file_count=$taskFiles.Count;archive_bytes=$taskCipher.Length+28;backup_ms=$taskBackupMs;total_ms=$taskStopwatch.ElapsedMilliseconds;verification_exit=$taskVerifyExit;provider_work_disabled=$true;source_untouched=$true}
    [IO.File]::WriteAllText((Join-Path $taskRestoreRoot 'timing.json'),($taskEvidence | ConvertTo-Json),[Text.UTF8Encoding]::new($false))
    $taskEvidence | ConvertTo-Json
    if ($taskVerifyExit -ne 0) { throw 'Restored-record verification reported issues. Read protected verification.json; no provider job executed.' }
}
finally {
    foreach ($taskName in $taskOriginalVariables.Keys) {
        if ($null -eq $taskOriginalVariables[$taskName]) { Remove-Item -LiteralPath "Env:$taskName" -ErrorAction SilentlyContinue }
        else { [Environment]::SetEnvironmentVariable($taskName,$taskOriginalVariables[$taskName],'Process') }
    }
    foreach ($taskPath in @($taskStaging,$taskZip,(Join-Path $taskRestoreRoot 'restore.zip'),(Join-Path $taskRestoreRoot 'business.dump'))) {
        $taskResolved = [IO.Path]::GetFullPath($taskPath)
        if (-not ($taskResolved.StartsWith($taskBackupRoot+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase) -or $taskResolved.StartsWith($taskRestoreRoot+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase))) { throw 'Refusing cleanup outside checked rehearsal directories.' }
        if (Test-Path -LiteralPath $taskResolved) { Remove-Item -LiteralPath $taskResolved -Recurse -Force }
    }
    $taskStopwatch.Stop()
}
