<?php

namespace Tests\Feature;

use App\Actions\SaveVendor;
use App\Models\AuditEntry;
use App\Models\Contact;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PhaseOneTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function vendorData(array $overrides = []): array
    {
        return array_replace(['company_name' => 'Straits Logistics', 'type' => 'freight_forwarder', 'services' => ['LCL', 'FCL'], 'coverage' => 'Port Klang to Singapore', 'communication_channel' => 'email'], $overrides);
    }

    public function test_guests_are_redirected_and_registration_does_not_exist(): void
    {
        $this->get('/login')->assertOk()->assertSee('Welcome back.');
        foreach (['/overview', '/vendors', '/vendors/create', '/profile', '/staff', '/settings'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
        $this->get('/')->assertRedirect('/overview');
    }

    public function test_active_staff_can_login_and_logout_with_session_regeneration(): void
    {
        $staff = User::factory()->create(['password' => 'SecurePassword123']);
        $this->post('/login', ['email' => strtoupper($staff->email), 'password' => 'SecurePassword123'])->assertRedirect('/overview');
        $this->assertAuthenticatedAs($staff);
        $this->get('/overview')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_bad_credentials_and_inactive_accounts_are_rejected(): void
    {
        $staff = User::factory()->create(['is_active' => false, 'password' => 'SecurePassword123']);
        $this->post('/login', ['email' => $staff->email, 'password' => 'SecurePassword123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $staff->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    public function test_inactive_staff_cannot_continue_existing_session(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->get('/vendors')->assertOk();
        $staff->update(['is_active' => false]);
        $this->get('/vendors')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_sensitive_authentication_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'no@example.test', 'password' => 'wrong'])->assertStatus(302);
        }
        $this->post('/login', ['email' => 'no@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_agents_cannot_read_or_mutate_admin_routes_directly(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $admin = $this->admin();
        $this->actingAs($agent);
        foreach (['/staff', '/staff/create', '/staff/'.$admin->id.'/edit', '/settings'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/staff', ['name' => 'Intruder'])->assertForbidden();
        $this->patch('/staff/'.$admin->id, ['role' => 'agent'])->assertForbidden();
        $this->post('/staff/'.$admin->id.'/password-setup')->assertForbidden();
        $this->patch('/settings', ['display_name' => 'Wrong'])->assertForbidden();
        $this->get('/overview')->assertDontSee('Administration activity');
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
        $this->assertDatabaseHas('company_settings', ['display_name' => 'LRS']);
    }

    public function test_admin_screens_render_and_create_staff_without_exposing_secrets(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->actingAs($admin);
        foreach (['/staff', '/staff/create', '/settings', '/profile'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->post('/staff', ['name' => 'New Agent', 'email' => ' NEW@EXAMPLE.TEST ', 'role' => 'agent', 'is_active' => '1'])->assertRedirect();
        $new = User::where('email', 'new@example.test')->firstOrFail();
        Notification::assertSentTo($new, ResetPassword::class);
        $this->assertFalse(Hash::check('password', $new->password));
        $this->assertDatabaseHas('audit_entries', ['record_id' => $new->id, 'action' => 'Staff created', 'actor_id' => $admin->id]);
        $this->assertStringNotContainsString('password', json_encode(AuditEntry::where('action', 'Staff created')->first()->changes));
        $this->get('/staff/'.$new->id.'/edit')->assertOk()->assertDontSee($new->password);
    }

    public function test_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        foreach ([['role' => 'agent', 'is_active' => '1'], ['role' => 'admin', 'is_active' => '0']] as $change) {
            $this->patch('/staff/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, ...$change])->assertSessionHasErrors('role');
        }
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_admin_can_deactivate_another_admin_and_revoke_sessions_and_tokens(): void
    {
        $actor = $this->admin();
        $target = $this->admin();
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        Password::createToken($target);
        $this->actingAs($actor)->patch('/staff/'.$target->id, ['name' => $target->name, 'email' => $target->email, 'role' => 'admin', 'is_active' => '0'])->assertRedirect('/staff');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
    }

    public function test_staff_email_identity_cannot_be_reassigned_on_edit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->patch('/staff/'.$admin->id, ['name' => $admin->name, 'email' => 'replacement@example.test', 'role' => 'admin', 'is_active' => '1'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('users', ['email' => $admin->email]);
    }

    public function test_password_reset_is_generic_and_reset_replaces_password_with_no_token_in_audit(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $response = $this->post('/forgot-password', ['email' => $user->email]);
        $response->assertSessionHas('status');
        $message = session('status');
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))->assertOk();
            $this->post('/reset-password', ['email' => $user->email, 'token' => $notification->token, 'password' => 'ReplacementPass123', 'password_confirmation' => 'ReplacementPass123'])->assertRedirect('/login');
            $this->assertTrue(Hash::check('ReplacementPass123', $user->fresh()->password));
            $this->assertStringNotContainsString($notification->token, json_encode(AuditEntry::all()));

            return true;
        });
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('status', $message);
    }

    public function test_inactive_and_expired_password_links_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $user->update(['is_active' => false]);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'ReplacementPass123', 'password_confirmation' => 'ReplacementPass123'];
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $user->update(['is_active' => true]);
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subHours(2)]);
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('ReplacementPass123', $user->fresh()->password));
    }

    public function test_agent_can_create_edit_and_change_vendor_status_with_audit(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->post('/vendors', $this->vendorData(['contact_name' => 'Aina', 'contact_email' => ' AINA@EXAMPLE.TEST ']))->assertRedirect();
        $vendor = Vendor::firstOrFail();
        $this->assertDatabaseHas('contacts', ['vendor_id' => $vendor->id, 'email' => 'aina@example.test', 'is_primary' => true]);
        $this->patch('/vendors/'.$vendor->id, $this->vendorData(['company_name' => 'Straits Branch', 'internal_notes' => 'Reviewed']))->assertRedirect();
        $this->patch('/vendors/'.$vendor->id.'/status', ['is_active' => '0'])->assertRedirect();
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'is_active' => false]);
        $this->get('/vendors/'.$vendor->id)->assertOk()->assertSee('excluded from future outreach');
        $this->patch('/vendors/'.$vendor->id.'/status', ['is_active' => '1'])->assertRedirect();
        $this->assertDatabaseHas('audit_entries', ['vendor_id' => $vendor->id, 'actor_id' => $agent->id, 'action' => 'Vendor deactivated']);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_missing_primary_contact_can_be_saved_and_is_clearly_flagged(): void
    {
        $this->actingAs($this->admin())->post('/vendors', $this->vendorData())->assertRedirect();
        $vendor = Vendor::firstOrFail();
        $this->get('/vendors/'.$vendor->id)->assertSee('A primary quotation contact is needed.');
        $this->get('/vendors?contact=missing')->assertSee('Straits Logistics');
        $this->get('/overview')->assertOk()->assertSee('active vendor needs a quotation contact');
    }

    public function test_validation_rejects_bad_vendor_fields_and_preserves_input(): void
    {
        $this->actingAs($this->admin())->from('/vendors/create')->post('/vendors', $this->vendorData(['company_name' => '', 'type' => 'invalid', 'services' => ['Air'], 'contact_name' => 'Name', 'contact_email' => 'not-email']))->assertSessionHasErrors(['company_name', 'type', 'services.0', 'contact_email'])->assertSessionHasInput('contact_name', 'Name');
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_primary_contact_transfer_is_atomic_and_duplicate_email_is_blocked(): void
    {
        $vendor = Vendor::factory()->create();
        $this->actingAs($this->admin());
        $this->post('/vendors/'.$vendor->id.'/contacts', ['name' => 'First', 'email' => 'FIRST@EXAMPLE.TEST', 'is_primary' => '1'])->assertRedirect();
        $this->post('/vendors/'.$vendor->id.'/contacts', ['name' => 'Second', 'email' => 'second@example.test', 'is_primary' => '1'])->assertRedirect();
        $this->assertSame(1, $vendor->contacts()->where('is_primary', true)->count());
        $this->assertDatabaseHas('contacts', ['email' => 'first@example.test', 'is_primary' => false]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'Primary contact changed', 'vendor_id' => $vendor->id]);
        $this->post('/vendors/'.$vendor->id.'/contacts', ['name' => 'Duplicate', 'email' => 'FIRST@example.test', 'is_primary' => '1'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('contacts', 2);
        $second = Contact::where('email', 'second@example.test')->firstOrFail();
        $this->patch('/vendors/'.$vendor->id.'/contacts/'.$second->id, ['name' => 'Second Updated', 'email' => 'second@example.test', 'is_primary' => '0'])->assertRedirect();
        $this->assertSame(0, $vendor->contacts()->where('is_primary', true)->count());
    }

    public function test_contact_route_binding_rejects_a_contact_from_another_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $other = Vendor::factory()->create();
        $contact = $other->contacts()->create(['name' => 'Other', 'email' => 'other@example.test', 'is_primary' => true]);
        $this->actingAs($this->admin())->get('/vendors/'.$vendor->id.'/contacts/'.$contact->id.'/edit')->assertNotFound();
        $this->patch('/vendors/'.$vendor->id.'/contacts/'.$contact->id, ['name' => 'Wrong', 'email' => 'wrong@example.test', 'is_primary' => true])->assertNotFound();
    }

    public function test_postgresql_enforces_one_primary_and_unique_normalized_contact_emails(): void
    {
        $vendor = Vendor::factory()->create();
        $vendor->contacts()->create(['name' => 'Primary', 'email' => 'same@example.test', 'is_primary' => true]);
        foreach ([['name' => 'Another', 'email' => 'new@example.test', 'is_primary' => true], ['name' => 'Duplicate', 'email' => 'SAME@EXAMPLE.TEST', 'is_primary' => false]] as $data) {
            try {
                DB::transaction(fn () => $vendor->contacts()->create($data));
                $this->fail('PostgreSQL constraint did not reject invalid data.');
            } catch (UniqueConstraintViolationException $e) {
                $this->assertSame('23505', $e->errorInfo[0]);
            }
        }
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_likely_duplicate_company_warns_without_blocking_legitimate_branches(): void
    {
        $this->actingAs($this->admin());
        $this->post('/vendors', $this->vendorData())->assertRedirect();
        $this->post('/vendors', $this->vendorData(['company_name' => 'STRAITS LOGISTICS', 'display_name' => 'Johor branch']))->assertRedirect();
        $this->assertDatabaseCount('vendors', 2);
        $this->get('/vendors/'.Vendor::latest('id')->first()->id)->assertSee('Possible duplicate company');
    }

    public function test_server_search_filters_and_pagination_use_real_records(): void
    {
        Vendor::factory()->count(14)->create(['company_name' => 'Alpha Partner', 'services' => ['LCL']]);
        $match = Vendor::factory()->create(['company_name' => 'Unique Ocean', 'type' => 'shipping_line', 'services' => ['FCL'], 'is_active' => false]);
        $match->contacts()->create(['name' => 'Quotation Desk', 'email' => 'unique@example.test', 'is_primary' => true]);
        $this->actingAs($this->admin());
        $this->get('/vendors?q=unique@example.test&status=inactive&type=shipping_line&service=FCL&contact=ready')->assertSee('Unique Ocean')->assertDontSee('Alpha Partner');
        $this->get('/vendors?service=LCL')->assertSee('Page 1 of 2')->assertSee('service=LCL');
        $this->get('/vendors?service=LCL&page=2')->assertSee('Page 2 of 2');
        $this->get('/vendors?q=does-not-exist')->assertSee('No vendors match this search');
        $this->get('/vendors?q=%25')->assertSee('No vendors match this search');
        $this->get('/vendors?type=invalid')->assertSessionHasErrors('type');
    }

    public function test_overview_readiness_excludes_inactive_vendors_and_filters_match_the_counts(): void
    {
        $ready = Vendor::factory()->create(['company_name' => 'Ready Active', 'is_active' => true]);
        $ready->contacts()->create(['name' => 'Desk', 'email' => 'ready@example.test', 'is_primary' => true]);
        Vendor::factory()->create(['company_name' => 'Needs Contact', 'is_active' => true]);
        $inactive = Vendor::factory()->create(['company_name' => 'Inactive With Contact', 'is_active' => false]);
        $inactive->contacts()->create(['name' => 'Desk', 'email' => 'inactive@example.test', 'is_primary' => true]);
        Vendor::factory()->create(['company_name' => 'Inactive Without Contact', 'is_active' => false]);

        $this->actingAs($this->admin())->get('/overview')->assertOk()
            ->assertViewHas('total', 4)
            ->assertViewHas('active', 2)
            ->assertViewHas('ready', 1)
            ->assertViewHas('missing', 1);
        $this->get('/vendors?status=active&contact=ready')->assertSee('Ready Active')->assertDontSee('Inactive With Contact')->assertDontSee('Needs Contact');
        $this->get('/vendors?status=active&contact=missing')->assertSee('Needs Contact')->assertDontSee('Inactive Without Contact');
        $this->get('/vendors?status=inactive')->assertSee('Inactive With Contact')->assertSee('Inactive Without Contact')->assertDontSee('Ready Active');
    }

    public function test_empty_directory_and_forms_render_useful_states(): void
    {
        $this->actingAs($this->admin());
        $this->get('/vendors')->assertOk()->assertSee('Your network starts here');
        $this->get('/overview')->assertOk()->assertSee('Start building your network');
        $this->get('/vendors/create')->assertOk();
        $vendor = Vendor::factory()->create();
        foreach (['/vendors/'.$vendor->id, '/vendors/'.$vendor->id.'/edit', '/vendors/'.$vendor->id.'/status', '/vendors/'.$vendor->id.'/contacts/create'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_profile_requires_current_password_and_revokes_other_sessions(): void
    {
        $user = User::factory()->create(['password' => 'InitialPassword123']);
        $this->actingAs($user)->patch('/profile', ['name' => 'Updated', 'password' => 'ReplacementPass123', 'password_confirmation' => 'ReplacementPass123', 'current_password' => 'incorrect'])->assertSessionHasErrors('current_password');
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->patch('/profile', ['name' => 'Updated', 'password' => 'ReplacementPass123', 'password_confirmation' => 'ReplacementPass123', 'current_password' => 'InitialPassword123'])->assertRedirect();
        $this->assertTrue(Hash::check('ReplacementPass123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->get('/profile')->assertOk();
        $this->assertStringNotContainsString('ReplacementPass123', json_encode(AuditEntry::all()));
        $this->assertDatabaseHas('audit_entries', ['action' => 'Staff password changed']);
    }

    public function test_company_preferences_are_validated_and_audited_with_utc_storage(): void
    {
        $this->actingAs($this->admin());
        $this->patch('/settings', ['display_name' => 'Company', 'timezone' => 'Invalid/Zone', 'currency' => 'XXX'])->assertSessionHasErrors(['timezone', 'currency']);
        $this->patch('/settings', ['display_name' => 'Company', 'timezone' => 'Asia/Singapore', 'currency' => 'SGD'])->assertRedirect();
        $this->assertDatabaseHas('company_settings', ['display_name' => 'Company', 'timezone' => 'Asia/Singapore', 'currency' => 'SGD']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'Company settings updated']);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_business_mutation_rolls_back_when_audit_write_fails(): void
    {
        $this->actingAs($this->admin());
        AuditEntry::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            app(SaveVendor::class)->handle($this->vendorData());
            $this->fail('Audit failure did not propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated audit failure', $e->getMessage());
        } finally {
            AuditEntry::flushEventListeners();
        }
        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_audit_history_has_no_write_routes(): void
    {
        $this->actingAs($this->admin());
        foreach (['POST', 'PATCH', 'DELETE'] as $method) {
            $this->call($method, '/audit-entries/1', [])->assertNotFound();
        }
        $vendor = Vendor::factory()->create();
        $this->delete('/vendors/'.$vendor->id)->assertStatus(405);
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);
    }

    public function test_secure_setup_command_prompts_for_password_and_has_no_default_accounts(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
        $this->artisan('lrs:admin')->expectsQuestion('Administrator name', 'Setup Admin')->expectsQuestion('Staff email', 'setup@example.test')->expectsQuestion('Password (12+ characters, upper/lowercase and number)', 'SecureSetupPass123')->expectsQuestion('Confirm password', 'SecureSetupPass123')->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'setup@example.test', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_staff_setup_mail_failure_and_throttling_show_warning_without_losing_account(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andThrow(new TransportException('Offline'));
        $this->actingAs($this->admin())->post('/staff', ['name' => 'Recoverable Account', 'email' => 'recover@example.test', 'role' => 'agent', 'is_active' => '1'])
            ->assertRedirect()->assertSessionHas('status', 'Staff account created.')->assertSessionHas('warning');
        $staff = User::where('email', 'recover@example.test')->firstOrFail();
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'is_active' => true]);
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_THROTTLED);
        $this->post('/staff/'.$staff->id.'/password-setup')->assertRedirect()->assertSessionHas('warning');
    }
}
