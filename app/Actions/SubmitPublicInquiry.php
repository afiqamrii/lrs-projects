<?php

namespace App\Actions;

use App\Http\Requests\PublicInquiryRequest;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\PublicSubmission;
use App\Models\User;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\PublicIntake;
use App\Support\Shipment;
use App\Support\WorkspaceData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SubmitPublicInquiry
{
    public function handle(PublicInquiryRequest $request): Inquiry
    {
        $paths = [];
        try {
            return DB::transaction(function () use ($request, &$paths): Inquiry {
                $key = PublicIntake::key($request, true);
                if ($key->inquiry_id) {
                    return Inquiry::findOrFail($key->inquiry_id);
                }
                $data = $request->validated();
                $settings = CompanySetting::whereKey(1)->sharedLock()->firstOrFail();
                if (! $settings->public_intake_enabled || $data['privacy_version'] !== $settings->public_privacy_version) {
                    throw ValidationException::withMessages(['privacy_version' => 'The inquiry availability or privacy notice changed. Review the refreshed form before submitting.']);
                }
                $contact = array_replace(['name' => null, 'email' => null, 'company' => null, 'phone' => null], $data['contact']);
                $inquiry = Inquiry::create([
                    'reference' => InquiryWorkflow::reference(),
                    'client_id' => null, 'client_contact_id' => null,
                    'owner_id' => $settings->public_intake_owner_id && User::whereKey($settings->public_intake_owner_id)->where('is_active', true)->exists() ? $settings->public_intake_owner_id : null,
                    'title' => mb_substr($data['shipment']['origin_location'].' → '.$data['shipment']['destination_location'], 0, 255),
                    'priority' => 'normal', 'status' => 'needs_review', 'received_at' => now(),
                    'response_due_at' => null, 'source_channel' => 'website', 'is_demo' => WorkspaceData::exampleEmail($contact['email']), 'public_contact' => $contact,
                    'original_source_text' => $data['additional_notes'] ?? null,
                    'shipment' => Shipment::normalize($data['shipment'], true),
                    'shipment_revision' => 1, 'lock_version' => 0,
                ]);
                app(StoreInquiryDocuments::class)->handle($inquiry, $request->file('files', []), $data['classification'], true, $paths);
                $documents = $inquiry->documents()->get()->map(fn ($doc) => ['id' => $doc->id, 'original_name' => $doc->original_name, 'checksum' => $doc->checksum, 'mime' => $doc->mime, 'size' => $doc->size, 'classification' => $doc->classification])->all();
                PublicSubmission::create(['inquiry_id' => $inquiry->id, 'idempotency_hash' => $key->token_hash, 'session_hash' => $key->session_hash, 'received_at' => $inquiry->received_at,
                    'snapshot' => ['contact' => array_replace($contact, ['email' => $request->attributes->get('submitted_email_text', $contact['email'])]), 'shipment' => $inquiry->shipment, 'additional_notes' => $data['additional_notes'] ?? null, 'documents' => $documents, 'privacy' => ['acknowledged' => true, 'version' => $settings->public_privacy_version, 'notice' => $settings->public_privacy_notice, 'acknowledged_at' => $inquiry->received_at->toIso8601String()]]]);
                DB::table('public_intake_keys')->where('token_hash', $key->token_hash)->update(['inquiry_id' => $inquiry->id]);
                Audit::record('Website inquiry received', $inquiry, details: ['source' => ['before' => null, 'after' => 'Original website submission preserved']], systemActor: 'System / public submission');
                app(RequestMailboxVerification::class)->handle($inquiry, true);

                return $inquiry;
            }, 1);
        } catch (\Throwable $exception) {
            $completed = DB::table('public_intake_keys')->where('token_hash', hash('sha256', (string) $request->input('intake_token')))->value('inquiry_id');
            if ($completed) {
                return Inquiry::findOrFail($completed);
            }
            foreach ($paths as $path) {
                Storage::disk('inquiry_documents')->delete($path);
            }
            throw $exception;
        }
    }
}
