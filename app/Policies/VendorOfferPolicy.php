<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VendorOffer;

class VendorOfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'agent'], true);
    }

    public function view(User $user, VendorOffer $offer): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, VendorOffer $offer): bool
    {
        return $this->viewAny($user);
    }
}
