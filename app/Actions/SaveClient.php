<?php

namespace App\Actions;

use App\Models\Client;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class SaveClient
{
    public function handle(array $data, ?Client $client = null): Client
    {
        return DB::transaction(function () use ($data, $client): Client {
            $creating = $client === null;
            $record = $creating ? new Client : Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $before = Audit::snapshot($record);
            $record->fill(array_intersect_key($data, array_flip($record->getFillable())))->save();
            Audit::record($creating ? 'Client created' : 'Client updated', $record, $before);
            if ($creating && ! empty($data['contact_email'])) {
                app(SaveClientContact::class)->handle($record, ['name' => $data['contact_name'], 'email' => $data['contact_email'], 'phone' => $data['contact_phone'] ?? null, 'role' => $data['contact_role'] ?? null, 'is_active' => true, 'is_primary' => true]);
            }

            return $record;
        });
    }
}
