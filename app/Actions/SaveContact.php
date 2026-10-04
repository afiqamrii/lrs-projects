<?php

namespace App\Actions;

use App\Models\Contact;
use App\Models\Vendor;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveContact
{
    public function handle(Vendor $vendor, array $data, ?Contact $contact = null): Contact
    {
        try {
            return DB::transaction(function () use ($vendor, $data, $contact): Contact {
                Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
                $data['is_active'] = $data['is_active'] ?? true;
                if (! $data['is_active']) {
                    $data['is_primary'] = false;
                }
                $creating = $contact === null;
                $contact = $creating ? $vendor->contacts()->make() : $vendor->contacts()->whereKey($contact->id)->firstOrFail();
                $before = Audit::snapshot($contact);
                if ($data['is_primary']) {
                    foreach ($vendor->contacts()->where('is_primary', true)->where('id', '!=', $contact->id ?? 0)->get() as $previous) {
                        $old = Audit::snapshot($previous);
                        $previous->update(['is_primary' => false]);
                        Audit::record('Primary contact changed', $previous, $old, $vendor->id);
                    }
                }
                $contact->fill($data)->save();
                Audit::record($creating ? 'Contact created' : 'Contact updated', $contact, $before, $vendor->id);

                return $contact;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => 'This email is already assigned to a contact at this vendor.']);
        }
    }
}
