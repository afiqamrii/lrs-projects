<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RfqApproval extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'approved_at' => 'immutable_datetime'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(RfqRevision::class, 'rfq_revision_id');
    }

    public function outlookDispatch(): ?MailDispatch
    {
        return MailDispatch::where('source_key', 'rfq:'.$this->id)->where('status', '!=', 'cancelled')->latest('id')->first();
    }

    public function dispatchLabel(): string
    {
        if ($this->dispatch) {
            return 'Manually recorded as sent';
        } $d = $this->outlookDispatch();

        return $d ? MailDispatch::STATES[$d->status] : 'Approved — not sent';
    }

    public function dispatch(): HasOne
    {
        return $this->hasOne(RfqDispatch::class);
    }
}
