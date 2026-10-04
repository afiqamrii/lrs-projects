<?php

namespace App\Console\Commands;

use App\Actions\SaveVendor;
use App\Models\Vendor;
use Illuminate\Console\Command;

class SeedDemo extends Command
{
    protected $signature = 'lrs:demo';

    protected $description = 'Add clearly fictional vendor examples in local/testing environments only';

    public function handle(SaveVendor $save): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Demo data is available only in local/testing environments.');

            return self::FAILURE;
        }
        foreach ([
            ['Straits Forwarding (Demo)', 'freight_forwarder', ['LCL', 'FCL', 'Clearance'], 'Port Klang · Singapore · Shanghai', 'Aina Rahman', 'aina@example.test'],
            ['Meridian Consolidation (Demo)', 'consolidator', ['LCL', 'Pickup'], 'Penang · Singapore · Hong Kong', 'Daniel Lim', 'daniel@example.test'],
            ['Northstar Shipping (Demo)', 'shipping_line', ['FCL', 'Insurance'], 'Asia Pacific · Europe', 'Mei Tan', 'mei@example.test'],
            ['Coastal Transport (Demo)', 'transporter', ['Pickup', 'Delivery'], 'Klang Valley · Johor', null, null],
        ] as [$name,$type,$services,$coverage,$contact,$email]) {
            if (Vendor::where('company_name', $name)->exists()) {
                continue;
            }
            $save->handle(['company_name' => $name, 'type' => $type, 'services' => $services, 'coverage' => $coverage, 'communication_channel' => 'email', 'internal_notes' => 'Fictional example created by the explicit development seed command.', 'contact_name' => $contact, 'contact_email' => $email]);
        }
        $this->info('Fictional vendor examples added. No staff credentials were created.');

        return self::SUCCESS;
    }
}
