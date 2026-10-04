<?php

namespace App\Mail;

use App\Models\CompanySetting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InquiryReceipt extends Mailable
{
    public function __construct(public string $reference, public string $confirmationToken, public string $approvedBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: CompanySetting::current()->display_name.' · Inquiry received '.$this->reference);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.inquiry-receipt', with: ['confirmationUrl' => rtrim(preg_replace('~/request-quote/?$~', '', config('public-intake.url')), '/').'/request-quote/confirm#'.$this->confirmationToken, 'companyName' => CompanySetting::current()->display_name]);
    }
}
