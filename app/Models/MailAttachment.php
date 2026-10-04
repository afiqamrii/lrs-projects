<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAttachment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['storage_path', 'source'];

    protected function casts(): array
    {
        return ['source' => 'encrypted:array', 'size' => 'integer', 'is_inline' => 'boolean'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }
}
