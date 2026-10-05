<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingHandoff extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'content', 'pdf_path'];

    protected function casts(): array
    {
        return ['current_number' => 'integer'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }

    public function current(): ?HandoffRevision
    {
        return $this->revisions()->where('number', $this->current_number)->first();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(HandoffRevision::class);
    }
}
