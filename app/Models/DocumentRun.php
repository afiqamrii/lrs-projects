<?php

namespace App\Models;

use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRun extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'selection' => 'array', 'blocks' => 'array', 'pages' => 'array', 'warnings' => 'array', 'total_pages' => 'integer', 'attempts' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InquiryDocument::class, 'document_id');
    }

    public function label(): string
    {
        return Processing::DOCUMENT[$this->state];
    }
}
