<?php

namespace App\Support;

class GraphFailure extends \RuntimeException
{
    public function __construct(public int $status = 0, public int $retryAfter = 30, public bool $ambiguous = false)
    {
        parent::__construct(match ($status) {
            401 => 'Outlook access expired or was revoked. Admin must reconnect.',403 => 'Outlook denied the required mailbox permission. Admin must verify Exchange rights.',410 => 'The folder cursor expired. A bounded resynchronization needs review.',429 => 'Outlook throttled this operation. Work will resume after Retry-After.',0 => 'The provider connection ended without a confirmed response.',default => 'Outlook could not complete this operation. Check the recovery record.'
        });
    }
}
