<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'lrs:admin';

    protected $description = 'Create an active administrator with a hidden password prompt';

    public function handle(): int
    {
        $name = text('Administrator name', required: true);
        $email = mb_strtolower(trim(text('Staff email', required: true)));
        $secret = password('Password (12+ characters, upper/lowercase and number)', required: true);
        $confirmation = password('Confirm password', required: true);
        $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $secret, 'password_confirmation' => $confirmation], ['name' => 'required|string|max:255', 'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users')], 'password' => ['required', 'confirmed', Password::defaults()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($name, $email, $secret): void {
            CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $admin = User::create(['name' => $name, 'email' => $email, 'password' => $secret, 'role' => 'admin', 'is_active' => true]);
            Audit::record('Staff created through secure setup', $admin);
        });
        $this->info('Administrator created. Sign in using the credentials you chose.');

        return self::SUCCESS;
    }
}
