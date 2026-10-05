<?php

namespace App\Models;

use App\Support\InquiryWorkflow;
use App\Support\Shipment;
use App\Support\WorkspaceData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Inquiry extends Model
{
    use HasFactory;

    public const STATUSES = ['draft' => 'Draft', 'needs_review' => 'Needs review', 'needs_client_information' => 'Needs client information', 'ready_for_sourcing' => 'Ready for sourcing', 'on_hold' => 'On hold', 'closed' => 'Closed'];

    public const CHANNELS = ['email' => 'Email', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'other' => 'Other', 'website' => 'Website form'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'public_contact' => 'array', 'shipment' => 'array', 'received_at' => 'immutable_datetime', 'response_due_at' => 'immutable_datetime', 'shipment_revision' => 'integer', 'lock_version' => 'integer'];
    }

    public function scopeWorkspace(Builder $query): Builder
    {
        return WorkspaceData::preview() ? $query->where('sample_set', WorkspaceData::SAMPLE_SET)->where('is_demo', true) : $query->where('is_demo', false);
    }

    public function publicSubmission(): HasOne
    {
        return $this->hasOne(PublicSubmission::class);
    }

    public function mailboxVerifications(): HasMany
    {
        return $this->hasMany(MailboxVerification::class)->orderByDesc('id');
    }

    public function mailboxConfirmed(): bool
    {
        return $this->mailboxVerifications()->where('email', $this->public_contact['email'] ?? '')->whereNull('invalidated_at')->whereNotNull('confirmed_at')->exists();
    }

    public function clientQuotation(): HasOne
    {
        return $this->hasOne(ClientQuotation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'client_contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ShipmentVersion::class)->orderByDesc('number');
    }

    public function clarifications(): HasMany
    {
        return $this->hasMany(Clarification::class)->latest('id');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(InquiryCommunication::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(InquiryDocument::class)->latest('id');
    }

    public function gaps(): array
    {
        return InquiryWorkflow::gaps($this);
    }

    public function snapshot(): array
    {
        return Shipment::snapshot($this);
    }

    public function snapshotHash(): string
    {
        return hash('sha256', json_encode($this->snapshot(), JSON_THROW_ON_ERROR));
    }

    public function eligible(): bool
    {
        return $this->status === 'ready_for_sourcing' && $this->gaps() === [] && $this->versions()->where('number', $this->shipment_revision)->where('snapshot_hash', $this->snapshotHash())->exists();
    }
}
