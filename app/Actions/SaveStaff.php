<?php

namespace App\Actions;

use App\Models\CompanySetting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveStaff
{
    public function handle(array $data, ?User $staff = null): User
    {
        try {
            return DB::transaction(function () use ($data, $staff): User {
                CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
                $creating = $staff === null;
                $staff = $creating ? new User : User::whereKey($staff->id)->lockForUpdate()->firstOrFail();
                if ($staff->role === 'admin' && $staff->is_active && ($data['role'] !== 'admin' || ! $data['is_active']) && User::where('role', 'admin')->where('is_active', true)->count() <= 1) {
                    throw ValidationException::withMessages(['role' => 'Keep at least one active admin. Create or promote another admin first.']);
                }
                $before = Audit::snapshot($staff);
                $staff->fill($data);
                if ($creating) {
                    $staff->password = Str::random(64);
                }
                $staff->save();
                if (! $staff->is_active) {
                    DB::table('sessions')->where('user_id', $staff->id)->delete();
                    DB::table('password_reset_tokens')->where('email', $staff->email)->delete();
                    $staff->forceFill(['remember_token' => null])->save();
                }
                Audit::record($creating ? 'Staff created' : 'Staff updated', $staff, $before);

                return $staff;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to a staff account.']);
        }
    }
}
