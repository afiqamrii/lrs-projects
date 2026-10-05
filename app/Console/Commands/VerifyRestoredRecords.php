<?php

namespace App\Console\Commands;

use App\Models\ClientDecision;
use App\Models\ClientQuotationApproval;
use App\Models\CompanySetting;
use App\Models\HandoffApproval;
use App\Models\HandoffEvent;
use App\Models\HandoffRevision;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use App\Models\MailEnvelope;
use App\Models\MailMessage;
use App\Models\OperationalMessageApproval;
use App\Models\VendorConfirmation;
use App\Support\Processing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VerifyRestoredRecords extends Command
{
    protected $signature = 'lrs:verify-restored-records {--manifest= : Protected JSON expected-count manifest}';

    protected $description = 'Verify an isolated locked-down restore without network calls or replaying jobs';

    public function handle(): int
    {
        $root = realpath(config('filesystems.disks.inquiry_documents.root'));
        $allowed = realpath(base_path('.tools/restore'));
        if (! config('operations.restore_lockdown') || config('database.default') !== 'pgsql'
            || ! str_ends_with(config('database.connections.pgsql.database'), '_restore_test')
            || ! $root || ! $allowed || ! str_starts_with(strtolower($root), strtolower($allowed).DIRECTORY_SEPARATOR)) {
            $this->error('Refusing verification outside an isolated _restore_test database, .tools/restore private namespace and explicit restore lockdown.');

            return self::FAILURE;
        }
        $failures = [];
        $counts = [];
        foreach (['inquiries', 'clients', 'vendors', 'shipment_versions', 'inquiry_documents', 'public_submissions', 'rfqs', 'rfq_approvals', 'vendor_offers', 'offer_selections', 'client_quotation_revisions', 'client_quotation_approvals', 'client_decisions', 'handoff_revisions', 'handoff_approvals', 'handoff_events', 'mail_messages', 'mail_dispatches', 'audit_entries'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $manifest = $this->option('manifest');
        if ($manifest) {
            $path = realpath($manifest);
            if (! $path || ! str_starts_with(strtolower($path), strtolower($allowed).DIRECTORY_SEPARATOR)) {
                $this->error('Manifest must be inside the isolated restore namespace.');

                return self::FAILURE;
            }
            $expected = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $restoredStates = json_decode(DB::selectOne("SELECT COALESCE(json_agg(s ORDER BY s.id),'[]'::json) AS states FROM (SELECT id,status,provider_draft_id,provider_sent_id FROM mail_dispatches) s")->states, true, 64, JSON_THROW_ON_ERROR);
            if (isset($expected['mail_states']) && $expected['mail_states'] !== $restoredStates) {
                $failures[] = 'Restored outbox states or provider identities differ. No replay is permitted.';
            }
            if ($expected['counts'] !== $counts) {
                $failures[] = 'Business/audit row counts differ from the captured backup manifest.';
            }
        }
        $files = 0;
        foreach (['inquiry_documents' => ['storage_path', 'checksum', 'size'], 'client_quotation_revisions' => ['pdf_path', 'pdf_checksum', 'pdf_size'], 'handoff_revisions' => ['pdf_path', 'pdf_checksum', 'pdf_size'], 'handoff_approvals' => ['pdf_path', 'pdf_checksum', 'pdf_size']] as $table => [$pathColumn, $checksumColumn, $sizeColumn]) {
            foreach (DB::table($table)->orderBy('id')->cursor() as $record) {
                $disk = Storage::disk('inquiry_documents');
                if (! $disk->exists($record->$pathColumn) || strlen($disk->get($record->$pathColumn)) !== (int) $record->$sizeColumn
                    || ! hash_equals($record->$checksumColumn, hash('sha256', $disk->get($record->$pathColumn)))) {
                    $failures[] = $table.' #'.$record->id.' private artifact missing or checksum differs.';
                }
                $files++;
            }
        }
        $encrypted = 0;
        foreach ([ClientDecision::class, VendorConfirmation::class, HandoffRevision::class, HandoffApproval::class, HandoffEvent::class, MailEnvelope::class, ClientQuotationApproval::class, OperationalMessageApproval::class] as $model) {
            foreach ($model::orderBy('id')->cursor() as $record) {
                try {
                    if (! hash_equals($record->digest, Processing::hash($record->snapshot))) {
                        $failures[] = $record->getTable().' #'.$record->id.' snapshot digest differs.';
                    }
                    $encrypted++;
                } catch (\Throwable) {
                    $failures[] = $record->getTable().' #'.$record->id.' decryption failed.';
                }
            }
        }
        foreach (MailMessage::orderBy('id')->cursor() as $record) {
            try {
                if (! hash_equals($record->source_hash, Processing::hash($record->source))) {
                    $failures[] = 'mail_messages #'.$record->id.' source digest differs.';
                }
                $encrypted++;
            } catch (\Throwable) {
                $failures[] = 'mail_messages #'.$record->id.' decryption failed.';
            }
        }
        foreach (MailboxConnection::orderBy('id')->cursor() as $record) {
            try {
                $record->access_token;
                $record->refresh_token;
                $record->aliases;
                $encrypted++;
            } catch (\Throwable) {
                $failures[] = 'Mailbox #'.$record->id.' encrypted credentials could not be opened.';
            }
        }
        $orphans = DB::table('client_decisions as d')->leftJoin('client_quotation_revisions as q', 'q.id', '=', 'd.client_quotation_revision_id')->whereNull('q.id')->count()
            + DB::table('mail_dispatches as d')->leftJoin('mail_envelopes as e', 'e.id', '=', 'd.mail_envelope_id')->whereNull('e.id')->count();
        if ($orphans) {
            $failures[] = 'Orphan business relationships detected.';
        }
        CompanySetting::current()->forceFill(['outbound_paused' => true, 'outbound_epoch' => CompanySetting::current()->outbound_epoch + 1, 'outbound_reason' => 'Isolated restore: keep all outbound work paused until explicit provider reconciliation.', 'receipt_mail_enabled' => false])->save();
        $evidence = ['database' => config('database.connections.pgsql.database'), 'counts' => $counts, 'private_artifacts_checked' => $files, 'encrypted_records_checked' => $encrypted,
            'accepted_or_uncertain_preserved' => MailDispatch::whereIn('status', ['accepted', 'observed', 'submitting', 'uncertain'])->count(), 'orphan_relationships' => $orphans,
            'outbound_paused' => true, 'restore_lockdown' => true, 'failures' => $failures, 'checked_at' => now()->toIso8601String()];
        $this->line(json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
