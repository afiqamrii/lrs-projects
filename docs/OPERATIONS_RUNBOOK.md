# LRS operations runbook · Phase 11

Updated 5 October 2026. Actual verified environment: local Windows/PowerShell 7, PHP 8.5.2, Laravel 13.34.0, PostgreSQL 17.11 on loopback port 55432, Blade/Tailwind 4/Vite 8 and the existing database queue/cache. The user authorized GitHub publication and internet deployment on 5 October 2026. The source repository is [afiqamrii/lrs-projects](https://github.com/afiqamrii/lrs-projects). A compatible live hosting account/project has not been supplied or connected. This operating package is not evidence of a deployed production service.

## Local startup and verification

Preserve the populated normal database, .env, encryption key and private storage. Do not run migrate:fresh, reset or a business-database rollback.

~~~powershell
Set-Location C:\Users\afiqa\LRS\lrs-projects
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan migrate --no-interaction
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
~~~

If the prepared PostgreSQL cluster is stopped:

~~~powershell
& .tools/pgsql/bin/pg_ctl.exe -D .tools/pgdata -l .tools/postgres.log -w start
~~~

Use the existing staff account at /login. Create an initial Admin only when needed with interactive php artisan lrs:admin; it prompts for a hidden password. No credentials or staff accounts are seeded by default. Local verification credentials remain in ignored .tools/browser-credentials.json. Admin manages Staff & access; active Agents share the normal inquiry/report operations.

In separate terminals with the same working directory and PHPRC:

~~~powershell
php artisan queue:listen database --queue=mail,health --tries=1 --timeout=330 --sleep=3 --no-interaction
php artisan queue:listen database --queue=extraction,ai,default --tries=1 --timeout=330 --sleep=3 --no-interaction
php artisan schedule:work --no-interaction
~~~

The extraction queue name is extraction, not documents. Database retry_after defaults to 450 seconds, longer than the listener's 330-second child-process deadline. This Windows PHP build has no pcntl, so queue:work cannot enforce its signal-based job timeout here. queue:listen creates a fresh Symfony child process with an enforced process deadline; this costs more boot time but suits the verified Windows runtime. Dispatch jobs still declare 300 seconds/one queue try; HTTP/tool limits and persisted ambiguity recovery remain essential if a child is terminated. Persisted preparation/backoff/reconciliation and AI attempt limits remain application controlled. Never increase automatic retries to solve an ambiguous send.

A one-shot safe heartbeat drill consumes only the health queue:

~~~powershell
php artisan schedule:run --no-interaction
php artisan queue:work database --queue=health --stop-when-empty --tries=1 --timeout=15 --no-interaction
~~~

The actual local drill observed the scheduler and WorkerHeartbeat. A separate queue:listen health-only drill also processed WorkerHeartbeat successfully and the owned listener was stopped afterward. Child-process timeout construction was confirmed in installed Laravel 13 Listener source; no deliberately hung provider request was created. It does not install continuous supervision. Health heartbeat age above five minutes is Stale observation. A successful health worker does not prove another queue is staffed.

Run sequential PostgreSQL tests only against the guarded lrs_test database:

~~~powershell
php vendor/bin/phpunit --log-junit .tools/phase11-full-tests.xml
php vendor/bin/pint --dirty --format agent
composer validate --strict --no-interaction
composer check-platform-reqs --no-interaction
composer audit --no-interaction
npm.cmd audit
php artisan view:cache --no-interaction
npm.cmd run build
~~~

Do not cache normal database configuration during tests. The test bootstrap refuses non-PostgreSQL databases or names without _test.

## Supervision and operational evidence

For this Windows environment, configure dedicated noninteractive Task Scheduler tasks under a restricted service account: one long-running worker task for each worker command above, start at boot, restart on failure, no overlapping instances, workspace as Start in, explicit PHP binary and PHPRC. Redirect task stdout to a protected rotating service log. Configure a separate every-minute task running php artisan schedule:run, disallow overlapping instances, and record task failure outside the web root. Do not run schedule:work and an every-minute scheduled task together. Windows tasks have not been installed in this repository.

On a different hosting target, adapt supervision to that target's process manager and scheduler before rollout; Redis/Horizon is not required. Configure graceful worker shutdown, restart-on-failure and adequate stop time for in-flight work. After a release stop/restart the Windows listener tasks; use php artisan queue:restart for daemon queue:work processes on a compatible target. Verify fresh scheduler/worker observations, queued work and failures. Monitor each serviced queue independently.

Operations health shows actual database backlog/reserved/oldest job, latest failed identities, scheduler and health-worker timestamps, per-connection/folder sync, uncertain/submitting mail, exhausted observations, pre-pause work, paused plans, extraction attention and the recorded UTC AI budget. No payload, original mail, OAuth token or failed-job exception is rendered. Private investigation uses dispatch IDs/UUIDs, folder IDs/generations and document/AI run IDs.

Keep LOG_CHANNEL=daily, an appropriate LOG_LEVEL and company-approved LOG_DAILY_DAYS on the eventual server. Protect storage/logs and process-manager logs; rotate them without deleting immutable business/audit records. The local log mailer stores password links in storage/logs/private-mail.log; it is developer-only and must not be published. Production password/receipt notifications require a separately configured company transport. External alert recipients/channels are not configured; this implementation sends no setup alerts.

## Emergency pause and safe recovery

Admin opens Operations health, writes Reason and evidence for this decision, acknowledges the effect, then selects Pause outgoing business mail. The server atomically advances an outbound authorization generation, records the actor/time/reason and audits the change. Incoming capture and manual business review continue.

Both providers and reminders check before preparation and at the frozen submission boundary. Mail already in submission cannot be recalled. Missing-draft or submission ambiguity remains Outcome uncertain and reconciliation-only. Accepted/observed mail is never treated as unsent.

To resume, Admin explicitly selects Explicitly resume outgoing control with a reviewed reason. Earlier queued work and reminder authorizations remain blocked by their older generation. For each exact failed pre-submission dispatch, inspect its source, recipients, bytes, current versions, parties, expiry, provider identity and response evidence, then use the existing per-dispatch recovery action. Accepted/submitting/uncertain recovery observes existing correlation evidence; it cannot create a replacement send. Old reminder plans need a new exact activation, retaining cumulative caps and reply/expiry gates.

Mailbox maintenance claims at most 100 expired leases, folders/preparation/reconciliation items per connection per tick; follow-up maintenance processes at most 100 oldest-touched plans and rotates them. Provider sync pages/import ceilings remain those of the existing adapters. Reconciliation stops automatically at 10 observations and appears in health for staff escalation. Safe preparation has a six-attempt ceiling. Bounded retry is not infinite retry. Never use queue:retry all for mail or recover an entire business backlog blindly.

For attachment failures, open the exact message/document run and retry its existing private import/extraction; preserve original source, cursor/page and approved checksum. Revoked OAuth requires Admin reconnecting the same authorized company identity; changed identity requires new sender review. Changing a default connection does not reroute old approvals or conversations. AI outages/unknown usage retain their reservations/evidence; use AI settings & reconciliation rather than inventing a zero charge. Manual review remains available.

## Backup policy decisions

The company must choose and record the production backup owner, destination, schedule, retention, permitted readers and recovery objectives. A proposed starting schedule for approval is an encrypted daily database/private-file checkpoint with seven daily, four weekly and three monthly copies, plus a checkpoint before migration. This is a proposal, not an active policy or SLA. Higher-frequency snapshots/WAL archiving must be selected if the company's tolerated data loss requires it.

RPO (maximum tolerable lost work) and RTO (maximum tolerable outage) remain Unselected. The local 11.52-second restore drill is not a recovery promise. Define off-device copies, deletion protection, access reviews and restore frequency before live operation. Suggested restore cadence for approval: before first rollout, after a material storage/key change and quarterly. Retention expiration applies to backup copies under authorized policy, not live source/audit history.

Cover the PostgreSQL business database/schema/constraints, all storage/app/private originals and generated PDFs, and separately protected APP_KEY, OAuth/server configuration and required runtime/configuration files. Preserve the original encryption key; regenerating it makes encrypted business/mail evidence unreadable. pg_dump of one database does not capture cluster roles/tablespaces. Provision/record those through the chosen infrastructure process without placing credentials in business exports.

## Executed isolated Windows restore

scripts/backup-rehearsal.ps1 is a bounded local PowerShell 7 rehearsal, not a production backup scheduler. It refuses nonlocal/nonloopback configuration, cached configuration, existing destinations and an unacknowledged quiet window. It restricts fresh backup/restore folders to the current Windows SID and SYSTEM. It creates a PostgreSQL custom dump and private-file SHA-256 manifest during a quiet window, encrypts the combined business/private archive with AES-256-GCM and independently protects its random encryption key and .env configuration using Windows DPAPI CurrentUser. No plaintext secret is included in the business archive.

DPAPI protection is tied to this Windows account/device. The rehearsal proves same-device recovery only; it is not an off-device disaster recovery solution. The production target must provide separately protected recoverable secret/key storage and a tested off-device decrypt/restore procedure.

For a local rehearsal, stop workers/scheduling and ensure no background writers. Preserve an already-active maintenance window; the following example assumes none exists:

~~~powershell
$env:PHPRC = Join-Path (Get-Location) '.tools/php.ini'
php artisan down --no-interaction
try { .\scripts\backup-rehearsal.ps1 -Quiesced }
finally { php artisan up --no-interaction }
~~~

The script decrypts the actual protected bundle into a new .tools/restore namespace, creates a unique lrs_p11_*_restore_test database from template0, restores without overwriting any database, refreshes planner statistics and invokes lrs:verify-restored-records. Child-process configuration uses the recovered APP_KEY and isolated private root, LRS_RESTORE_LOCKDOWN=true, MAIL_MAILER=array, no live AI key and no demo/provider work. No web server, worker or scheduler is started there.

Verification compares 19 business/audit table counts and exact original outbox states/provider identities, private document/approved PDF sizes and hashes, encrypted snapshot/source digests, encrypted mailbox-field usability and representative lineage. The restored singleton remains outgoing-paused with a newer authorization generation and receipt notifications disabled. Accepted/submitting/uncertain evidence is preserved; there is no outbox replay. Source counts, original file hashes and outbox identities are checked again. Plain staging archives/configuration are cleaned up within the exact checked fresh directories; protected encrypted backups and isolated private files remain for review.

Final executed evidence is under .tools/restore/20261005040443_2282a0fc:
verification.json and timing.json. Source lrs remains intact. Restore database:
lrs_p11_20261005040443_2282a0fc_restore_test.
52 private files, 34 linked artifacts and 65 encrypted records passed; 12 accepted/observed/submitting/uncertain dispatches retained; zero orphan relationships/failures. Backup encryption took 4.239 seconds and the complete measured drill took 11.520 seconds. Database, private originals, PDFs and audit history were restored together. Earlier protected rehearsal evidence is retained; no source evidence was modified to make verification pass.

The script's in-memory archive ceiling is 256 MiB and it covers this single local database, not PostgreSQL high availability, WAL/PITR, role provisioning or distributed-object-store snapshots. Select streaming/provider-native backup tooling for a larger deployment. Investigate failures in the protected verification file. Keep a restored environment isolated and locked down until the company approves the target, reconciles provider evidence and performs individual fresh recovery.

## Reviewable rollout and rollback

Before selecting a production target: approve the genuine company identity/timezone/currency, access owners, operational requirements, reminder policies, mailboxes/recipients, backups/RPO/RTO, retention and logging. Create genuine staff accounts; do not clone the fictional populated lrs database or QA users into production. Local seed commands reject production and the default seeder creates no accounts or approvals.

Provision the target with PHP 8.5 and required extensions (verified by composer check-platform-reqs), installed lock files, PostgreSQL, writable protected storage/bootstrap/cache and a document root of public only. Deny direct web access to .env, .tools, vendor, logs, source and private storage. Do not expose storage/app/private through a public storage link. Configure HTTPS/trusted proxy behavior, APP_ENV=production, APP_DEBUG=false, correct APP_URL, SESSION_SECURE_COOKIE=true and SameSite=lax. Protect cached configuration because it contains server secrets.

Use composer install --no-dev --prefer-dist --no-interaction and npm ci / npm run build in the release build. Preserve APP_KEY and company/server secrets through the approved secret store. Configure actual Microsoft/Google OAuth redirect URLs to match the registered target exactly, consent/scopes/authorized mailbox rights and verified Gmail aliases. See phase-9-handoff.md for Google scopes/limitations and historical HANDOFF for Microsoft setup. A configured mailbox alone does not authorize sending.

Provision Poppler and Tesseract/language data using the existing EXTRACTION_* environment keys; configure process limits, 20-page/12-million-pixel/200,000-character defaults and bounded archive/Office decoding. Dompdf 3.1.6 renders locally with remote assets disabled and private fonts/files; no browser/network PDF renderer is added. Manual review must remain usable when extraction or AI fails.

Pause outgoing mail in the current deployment and stop release workers, enter a controlled quiet window, take/verify an encrypted DB/private/config backup, then apply additive php artisan migrate --force --no-interaction on the target. Use php artisan optimize for deployment caches only after target environment configuration is correct. Restart supervised workers, install the every-minute scheduler and check actual health observations, permissions, URLs, private artifacts and a controlled authorized pilot. Resume only after current-source review; old work still needs individual recovery. No live production deployment has been executed. GitHub source publication does not provision the PHP runtime, PostgreSQL, protected persistent files, supervised queues or scheduler. Connect a compatible Laravel hosting project before applying these rollout steps.

Rollback initially means pausing outgoing release, stopping workers, preserving evidence and switching to an explicitly tested compatible code release. Do not assume old code can safely consume new schema or authorization controls. The Phase 11 migration refuses non-testing rollback. Keep additive schema/history; do not drop columns/tables or restore an older business backup over accepted provider evidence. A restored business checkpoint remains isolated until reconciliation and company approval.

Primary references consulted for installed versions:
[Laravel 13 deployment](https://laravel.com/docs/13.x/deployment),
[queues](https://laravel.com/docs/13.x/queues),
[scheduler](https://laravel.com/docs/13.x/scheduling),
[PostgreSQL 17 SQL dump](https://www.postgresql.org/docs/17/backup-dump.html),
[pg_restore](https://www.postgresql.org/docs/17/app-pgrestore.html).
The supplied current-version PostgreSQL backup guidance was also consulted; execution uses installed PostgreSQL 17.
