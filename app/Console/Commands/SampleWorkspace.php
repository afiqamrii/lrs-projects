<?php

namespace App\Console\Commands;

use App\Actions\ManageOffer;
use App\Models\CompanySetting;
use App\Models\User;
use App\Models\VendorOffer;
use App\Support\WorkspaceData;
use Carbon\CarbonImmutable;
use Database\Seeders\ProfessionalSampleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class SampleWorkspace extends Command
{
    protected $signature = 'lrs:sample-workspace {--apply : Add the fictional business preview without deleting evidence} {--real : Show real incoming records by default} {--align-validity : Correct only fictional preview validity inputs from their preserved exact source text}';

    protected $description = 'Load realistic fictional logistics records locally; no Outlook or AI calls';

    public function handle(ProfessionalSampleSeeder $seeder): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Fictional data is restricted to local/testing environments.');

            return self::FAILURE;
        }
        if ($this->option('real')) {
            CompanySetting::current()->update(['workspace_data_mode' => 'real']);
            $this->info('Default workspace now shows real incoming records. All sample history is preserved.');

            return self::SUCCESS;
        }
        if ($this->option('align-validity')) {
            $staff = User::where('role', 'admin')->where('is_active', true)->firstOrFail();
            $this->info('Audited fictional validity corrections: '.$this->alignValidity($staff));

            return self::SUCCESS;
        }
        if (! $this->option('apply')) {
            $this->info('Use --apply to add the explicitly fictional business preview. It preserves existing evidence and sends no emails.');

            return self::SUCCESS;
        }
        $staff = User::where('role', 'admin')->where('is_active', true)->first();
        if (! $staff) {
            $this->error('Create an active administrator before loading the preview.');

            return self::FAILURE;
        }
        $result = $seeder->seed($staff);
        $this->info('Fictional business preview ready: '.json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function alignValidity(User $staff): int
    {
        Auth::login($staff);
        $changed = 0;
        foreach (VendorOffer::whereHas('inquiry', fn ($q) => $q->where('is_demo', true)->where('sample_set', WorkspaceData::SAMPLE_SET))->get() as $offer) {
            $revision = $offer->current();
            if (! $revision || $offer->supersededBy() || ! preg_match('/Validity: ([0-9]{2} [A-Za-z]{3} [0-9]{4}) at 18:00 Malaysia time/', $offer->source['text'] ?? '', $match)) {
                continue;
            }
            $deadline = CarbonImmutable::createFromFormat('!d M Y H:i', $match[1].' 18:00', 'Asia/Kuala_Lumpur')->utc();
            if (! empty($revision->payload['valid_until']) && $deadline->equalTo(CarbonImmutable::parse($revision->payload['valid_until']))) {
                continue;
            }
            $payload = $revision->payload;
            $payload['valid_until'] = $deadline->toIso8601String();
            $payload['expected_revision'] = $offer->current_number;
            $payload['change_reason'] = 'Fictional fixture correction: align validity with the original explicit vendor date and Malaysia time; original evidence retained.';
            app(ManageOffer::class)->save($offer, $staff, $payload, $revision->reviewed_at !== null, $payload['proposal_review'] ?? []);
            $changed++;
        }

        return $changed;
    }
}
