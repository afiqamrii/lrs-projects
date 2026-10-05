<?php

namespace App\Support;

class GmailFailure extends GraphFailure
{
    public function __construct(int $status, int $retryAfter = 30, bool $ambiguous = false)
    {
        parent::__construct($status, min(86400, max(1, $retryAfter)), $ambiguous);
        $this->message = match ($status) {
            401, 403 => 'Gmail access is unavailable or revoked. Reconnect the authorized account and check consent.',
            404 => 'Gmail evidence is no longer available. Preserve the record and review controlled recovery.',
            429 => 'Gmail quota or rate limit reached. Work is retained for bounded retry.',
            default => 'Gmail did not return a conclusive successful response. Inspect existing evidence before recovery.',
        };
    }
}
