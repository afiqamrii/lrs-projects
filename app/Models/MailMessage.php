<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailMessage extends Model
{
    use HasFactory;

    public const CLASSES = ['acceptance' => 'Acceptance · staff reviewed; no booking', 'revision_request' => 'Revision requested · staff review', 'quote' => 'Quotation received · not commercially reviewed', 'question' => 'Question', 'decline' => 'Decline', 'out_of_office' => 'Out of office', 'bounce' => 'Bounce evidence', 'noise' => 'Automated / mailing-list noise', 'customer' => 'Customer inquiry / reply', 'other' => 'Other · review'];

    protected $guarded = ['id'];

    protected $hidden = ['source'];

    protected function casts(): array
    {
        return ['response_reviewed_at' => 'immutable_datetime', 'source' => 'encrypted:array', 'candidates' => 'array', 'is_demo' => 'boolean', 'lock_version' => 'integer', 'received_at' => 'immutable_datetime', 'deleted_at_provider' => 'immutable_datetime'];
    }

    public function operationalMessage(): BelongsTo
    {
        return $this->belongsTo(OperationalMessage::class);
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RfqRevision::class, 'rfq_revision_id');
    }

    public function clientQuotationRevision(): BelongsTo
    {
        return $this->belongsTo(ClientQuotationRevision::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }

    public function text(): string
    {
        $body = $this->source['body'] ?? [];
        if (strtolower($body['contentType'] ?? 'text') === 'html') {
            $text = preg_replace('/<(script|style|head)\b[^>]*>.*?<\/\1>/is', '', $body['content'] ?? '');

            return html_entity_decode(strip_tags(preg_replace('/<(?:br|\/p|\/div|\/tr)\b[^>]*>/i', "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $body['content'] ?? '';
    }

    public function oldRevision(): bool
    {
        return $this->revision && ($this->revision->number !== $this->revision->rfq->current_number || $this->revision->rfq->round->version->number !== $this->revision->rfq->inquiry->shipment_revision);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(MailboxConnection::class, 'mailbox_connection_id');
    }

    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }
}
