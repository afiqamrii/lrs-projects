<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttentionTask extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime'];
    }

    public function targetUrl(): string
    {
        if ($this->client_quotation_revision_id) {
            return route('lifecycle.decision', [$this->inquiry_id, $this->client_quotation_revision_id, 'message' => $this->mail_message_id]);
        }
        if ($this->vendor_reconfirmation_id) {
            return route('lifecycle.vendor', [$this->inquiry_id, $this->vendor_reconfirmation_id, 'message' => $this->mail_message_id]);
        }

        return route('lifecycle.handoff', [$this->inquiry_id, 'message' => $this->mail_message_id]);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FollowupPlan::class, 'followup_plan_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }
}
