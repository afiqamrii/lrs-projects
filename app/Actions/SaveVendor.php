<?php

namespace App\Actions;

use App\Models\Vendor;
use App\Support\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveVendor
{
    public function handle(array $data, ?Vendor $vendor = null): Vendor
    {
        return DB::transaction(function () use ($data, $vendor): Vendor {
            $creating = $vendor === null;
            $vendor = $creating ? new Vendor : Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $before = Audit::snapshot($vendor);
            $vendor->fill(Arr::except($data, ['contact_name', 'contact_email', 'contact_role', 'contact_phone', 'is_active']));
            if ($creating) {
                $vendor->is_active = true;
            }
            $vendor->save();
            Audit::record($creating ? 'Vendor created' : 'Vendor updated', $vendor, $before, $vendor->id);
            if ($creating && ! empty($data['contact_email'])) {
                $contact = $vendor->contacts()->create(['name' => $data['contact_name'], 'email' => $data['contact_email'], 'role' => $data['contact_role'] ?? null, 'phone' => $data['contact_phone'] ?? null, 'is_primary' => true]);
                Audit::record('Contact created', $contact, [], $vendor->id);
            }

            return $vendor;
        });
    }
}
