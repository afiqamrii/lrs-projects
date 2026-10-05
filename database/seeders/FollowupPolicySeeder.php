<?php

namespace Database\Seeders;

use App\Actions\ManageFollowups;
use App\Models\FollowupPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;

class FollowupPolicySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->where('is_active', true)->firstOrFail();
        foreach (['rfq', 'client_quote'] as $kind) {
            if (! FollowupPolicy::latestFor($kind)) {
                app(ManageFollowups::class)->policy($admin, ManageFollowups::defaults($kind) + ['kind' => $kind, 'expected_number' => 0, 'enabled' => false, 'reason' => 'Disabled starter examples; company and exact Agent approval required.']);
            }
        }
    }
}
