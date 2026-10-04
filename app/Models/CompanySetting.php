<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = ['display_name', 'timezone', 'currency', 'public_intake_enabled', 'public_service_intro', 'public_contact_email', 'public_contact_phone', 'public_contact_address', 'public_privacy_notice', 'public_privacy_version', 'public_intake_owner_id', 'receipt_mail_enabled', 'receipt_mail_body', 'rfq_reply_name', 'rfq_reply_email', 'rfq_signature'];

    protected function casts(): array
    {
        return ['public_intake_enabled' => 'boolean', 'receipt_mail_enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return static::findOrFail(1);
    }
}
