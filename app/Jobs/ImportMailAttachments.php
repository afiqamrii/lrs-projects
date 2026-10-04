<?php

namespace App\Jobs;

use App\Actions\StoreInquiryDocuments;
use App\Models\Inquiry;
use App\Models\MailAttachment;
use App\Models\MailboxFolder;
use App\Models\MailMessage;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\InquiryUploads;
use App\Support\Mailboxes;
use App\Support\Processing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportMailAttachments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $messageId, public ?int $folderId) {}

    public function handle(): void
    {
        $lock = Cache::lock('mail-attachments-'.$this->messageId, 360);
        if (! $lock->get()) {
            return;
        }
        try {
            $m = MailMessage::findOrFail($this->messageId);
            $f = MailboxFolder::find($this->folderId);
            if ($m->direction !== 'incoming' || ! $f || ! $f->mailbox->usable() || $f->identity_hash !== $f->mailbox->identity_hash) {
                return;
            }
            $path = GraphMail::messages($f->mailbox_id).'/'.rawurlencode($m->provider_id);
            $graph = app(GraphMail::class);
            $items = $graph->list($f->mailbox, $path.'/attachments?$select=id,name,contentType,size,isInline', 100);
            $total = 0;
            $failures = [];
            foreach ($items as $index => $item) {
                $a = MailAttachment::firstOrCreate(['mail_message_id' => $m->id, 'provider_id' => $item['id']], ['source' => $item, 'source_hash' => Processing::hash($item), 'name' => InquiryUploads::safeName($item['name'] ?? 'document'), 'mime' => $item['contentType'] ?? 'application/octet-stream', 'size' => $item['size'] ?? 0, 'type' => $item['@odata.type'] ?? 'unknown', 'is_inline' => $item['isInline'] ?? false]);
                $total += $a->size;
                if ($index >= config('inquiries.document_limit') || $a->size > config('inquiries.upload_max_kb') * 1024 || $total > config('mailbox.message_attachment_total') || $a->type !== '#microsoft.graph.fileAttachment') {
                    $a->update(['state' => 'unsupported', 'error' => 'Unsupported attachment kind, file size, file count or message total. Original provider metadata retained; reduce/recover the file manually through private documents.']);
                    $failures[] = $a->name;

                    continue;
                }
                try {
                    if ($a->state !== 'stored') {
                        $bytes = $graph->call($f->mailbox, 'GET', $path.'/attachments/'.rawurlencode($a->provider_id).'/$value');
                        if (strlen($bytes) > config('inquiries.upload_max_kb') * 1024 || strlen($bytes) !== $a->size) {
                            throw new \RuntimeException('Attachment byte length differs from metadata');
                        }
                        $privatePath = 'attachments/'.$m->id.'/'.hash('sha256', $a->provider_id);
                        Storage::disk('mailbox')->put($privatePath, $bytes);
                        $upload = new UploadedFile(Storage::disk('mailbox')->path($privatePath), $a->name, null, null, true);
                        $type = InquiryUploads::type($upload);
                        if (! $type) {
                            Storage::disk('mailbox')->delete($privatePath);
                            $a->update(['state' => 'unsupported', 'error' => 'Actual file content is not a supported safe document type. No public link or active preview.']);
                            $failures[] = $a->name;

                            continue;
                        }
                        $a->update(['state' => 'stored', 'storage_path' => $privatePath, 'mime' => $type['mime'], 'checksum' => hash('sha256', $bytes), 'error' => null]);
                    }
                    if ($m->fresh()->inquiry_id) {
                        $this->copyToInquiry($a, $m->fresh()->inquiry_id);
                    }
                } catch (\Throwable $e) {
                    if ($e instanceof GraphFailure && in_array($e->status, [401, 403], true)) {
                        app(Mailboxes::class)->pause($e->getMessage(), $f->mailbox);
                    } $a->update(['state' => $a->storage_path ? 'stored' : 'failed', 'error' => 'File import or inquiry association failed. Retry after reconnecting/restoring access; retained source metadata and bytes remain private.']);
                    $failures[] = $a->name;
                }
            }
            $m->update(['attachment_state' => $failures ? 'partial' : 'complete', 'attachment_error' => $failures ? 'Needs attention: '.implode(', ', array_slice($failures, 0, 10)) : null]);
        } catch (GraphFailure $e) {
            if (in_array($e->status, [401, 403], true)) {
                app(Mailboxes::class)->pause($e->getMessage(), $f->mailbox);
            } MailMessage::whereKey($this->messageId)->update(['attachment_state' => 'failed', 'attachment_error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            MailMessage::whereKey($this->messageId)->update(['attachment_state' => 'failed', 'attachment_error' => 'Attachment listing could not be completed. Retry explicitly; no file is silently discarded.']);
        } finally {
            $lock->release();
        }
    }

    private function copyToInquiry(MailAttachment $a, int $inquiryId): void
    {
        $paths = [];
        try {
            DB::transaction(function () use ($a, $inquiryId, &$paths): void {
                $i = Inquiry::whereKey($inquiryId)->lockForUpdate()->firstOrFail();
                if (DB::table('mail_attachment_documents')->where('mail_attachment_id', $a->id)->where('inquiry_id', $i->id)->exists()) {
                    return;
                }
                $file = new UploadedFile(Storage::disk('mailbox')->path($a->storage_path), $a->name, null, null, true);
                app(StoreInquiryDocuments::class)->handle($i, [$file], 'other', false, $paths, systemActor: 'System / Outlook import');
                $doc = $i->documents()->where('checksum', $a->checksum)->firstOrFail();
                DB::table('mail_attachment_documents')->insert(['mail_attachment_id' => $a->id, 'inquiry_id' => $i->id, 'inquiry_document_id' => $doc->id]);
            });
        } catch (\Throwable $e) {
            foreach ($paths as $path) {
                Storage::disk('inquiry_documents')->delete($path);
            } throw $e;
        }
    }
}
