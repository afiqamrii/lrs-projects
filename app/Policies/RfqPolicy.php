<?php

namespace App\Policies;

use App\Models\Rfq;
use App\Models\User;

class RfqPolicy
{
    public function view(User $user, Rfq $rfq): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'agent'], true);
    }

    public function update(User $user, Rfq $rfq): bool
    {
        return $this->view($user, $rfq);
    }

    public function approve(User $user, Rfq $rfq): bool
    {
        return $this->view($user, $rfq);
    }
}
