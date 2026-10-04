<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\ClientContact;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveClientContact
{
    public function handle(Client $client, array $data, ?ClientContact $contact = null): ClientContact
    {
        try {
            return DB::transaction(function () use ($client, $data, $contact): ClientContact {
                Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
                $creating = $contact === null;
                $record = $creating ? $client->contacts()->make() : $client->contacts()->whereKey($contact->id)->firstOrFail();
                $before = Audit::snapshot($record);
                $data['is_primary'] = (bool) $data['is_primary'] && (bool) $data['is_active'];
                if ($data['is_primary']) {
                    foreach ($client->contacts()->where('is_primary', true)->where('id', '!=', $record->id ?? 0)->get() as $previous) {
                        $old = Audit::snapshot($previous);
                        $previous->update(['is_primary' => false]);
                        Audit::record('Primary client contact changed', $previous, $old);
                    }
                }
                $record->fill($data)->save();
                Audit::record($creating ? 'Client contact created' : 'Client contact updated', $record, $before);

                return $record;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to a contact at this client.']);
        }
    }
}
