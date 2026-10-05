<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailboxConnection;
use App\Models\PublicSubmission;
use App\Models\User;
use App\Models\Vendor;
use App\Support\OperationalReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspaceReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_earlier_website_evidence_with_omitted_optional_fields_remains_readable_and_unchanged(): void
    {
        $inquiry = Inquiry::factory()->create([
            'source_channel' => 'website',
            'public_contact' => ['name' => 'Farah Ahmad', 'email' => 'farah@peninsula.example'],
        ]);
        $submission = PublicSubmission::factory()->create([
            'inquiry_id' => $inquiry->id,
            'snapshot' => [
                'contact' => ['name' => 'Farah Ahmad', 'email' => 'farah@peninsula.example'],
                'shipment' => [],
                'privacy' => ['acknowledged' => true, 'version' => 'early.1'],
            ],
        ]);
        $original = $submission->fresh()->snapshot;
        $this->get(route('inquiries.show', $inquiry))->assertRedirect(route('login'));
        foreach (['admin', 'agent'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            foreach (['overview', 'shipment', 'documents', 'activity'] as $section) {
                $this->get(route('inquiries.show', ['inquiry' => $inquiry, 'section' => $section]))
                    ->assertOk()->assertSee('Not supplied')->assertSee('Time not retained');
            }
            $this->get(route('inquiries.public-contact.edit', $inquiry))
                ->assertOk()->assertSee('Privacy notice text was not retained');
        }
        $this->assertSame($original, $submission->fresh()->snapshot);
    }

    public function test_directory_readiness_excludes_inactive_primary_contacts_and_inactive_vendors(): void
    {
        $ready = Vendor::factory()->create(['is_active' => true]);
        $ready->contacts()->create(['name' => 'Mei Tan', 'email' => 'mei@harbour.example', 'is_active' => true, 'is_primary' => true]);
        $inactiveContact = Vendor::factory()->create(['is_active' => true]);
        $inactiveContact->contacts()->create(['name' => 'Former contact', 'email' => 'former@harbour.example', 'is_active' => false, 'is_primary' => true]);
        Vendor::factory()->create(['is_active' => true]);
        Vendor::factory()->create(['is_active' => false]);
        $this->actingAs(User::factory()->create());
        $this->get('/overview')->assertOk()->assertViewHas('ready', 1)->assertViewHas('missing', 2);
        $this->get('/vendors?contact=missing')->assertOk()
            ->assertViewHas('vendors', fn ($records): bool => $records->contains('id', $inactiveContact->id) && ! $records->contains('id', $ready->id));
        $this->assertNull($inactiveContact->fresh()->primaryContact);
        $this->assertSame(1, $inactiveContact->contacts()->count());
    }

    public function test_inbox_reports_each_provider_and_incoming_state_without_exposing_credentials_or_calling_providers(): void
    {
        Http::preventStrayRequests();
        $outlook = MailboxConnection::factory()->create(['state' => 'disconnected', 'incoming_enabled' => false]);
        $gmail = MailboxConnection::factory()->gmail()->create(['incoming_enabled' => true]);
        CompanySetting::current()->update(['outbound_mailbox_id' => $gmail->id]);
        $this->actingAs(User::factory()->create(['role' => 'agent']))
            ->get('/mail')->assertOk()->assertSee('Gmail')->assertSee('Outlook')
            ->assertSee('Disconnected · incoming paused')->assertSee('Connected · incoming enabled')
            ->assertDontSee('Outlook is connected')->assertDontSee('test-access')->assertDontSee('test-refresh')
            ->assertDontSee('Manage connections');
        $this->assertSame('disconnected', $outlook->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_commercial_activity_with_vendor_and_inquiry_context_links_back_to_the_inquiry(): void
    {
        $staff = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $inquiry = Inquiry::factory()->create();
        AuditEntry::create([
            'actor_id' => $staff->id,
            'actor_name' => $staff->name,
            'action' => 'Vendor commercial review recorded',
            'record_type' => Inquiry::class,
            'record_id' => $inquiry->id,
            'record_label' => $inquiry->reference,
            'vendor_id' => $vendor->id,
            'inquiry_id' => $inquiry->id,
            'changes' => [],
            'created_at' => now(),
        ]);
        $this->actingAs($staff)->get('/overview')->assertOk()
            ->assertSee('href="'.route('inquiries.show', $inquiry).'">'.$inquiry->reference.'</a>', false)
            ->assertDontSee('href="'.route('vendors.show', $vendor).'">'.$inquiry->reference.'</a>', false);
    }

    public function test_csv_neutralizes_formula_prefixes_and_removes_nul_in_both_branches(): void
    {
        $this->assertSame("' =1+2", OperationalReports::csvCell(" \0=1+2"));
        $this->assertSame('Plain text', OperationalReports::csvCell("Plain\0 text"));
        $this->assertSame("'\t@SUM(A1)", OperationalReports::csvCell("\t@SUM(A1)"));
    }
}
