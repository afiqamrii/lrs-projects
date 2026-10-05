<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class HandoffPolicySeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Handoff policy requires explicit Admin workflow approval at /settings/handoff. No implicit policy was seeded.');
    }
}
