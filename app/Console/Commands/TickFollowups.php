<?php

namespace App\Console\Commands;

use App\Actions\ManageFollowups;
use App\Models\FollowupPlan;
use App\Models\MailboxConnection;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class TickFollowups extends Command
{
    protected $signature = 'lrs:followup-tick {--plan= : Process one persisted plan} {--fixture-at= : Local synthetic plan clock, ISO-8601 only}';

    protected $description = 'Claim approved due follow-up stages without provider calls or duplicate logical sends';

    public function handle(ManageFollowups $action): int
    {
        $q = FollowupPlan::query()->orderBy('updated_at')->orderBy('id');
        if ($this->option('plan')) {
            $q->whereKey($this->option('plan'));
        } else {
            $q->whereIn('state', ['active', 'held']);
        }
        if ($this->option('fixture-at')) {
            $plan = $q->first();
            $mailbox = $plan?->authorization ? MailboxConnection::where('identity_hash', $plan->authorization->snapshot['identity_hash'])->first() : null;
            if (! $plan || ! in_array($plan->state, ['active', 'held'], true) || ! $this->option('plan') || ! $plan->inquiry->is_demo || ! $mailbox?->is_demo || ! $mailbox->usable() || ! config('mailbox.demo_enabled') || ! app()->environment('local', 'testing')) {
                $this->error('Fixture time requires one local synthetic plan and an enabled, connected fixture mailbox.');

                return self::FAILURE;
            }
            try {
                $date = CarbonImmutable::parse($this->option('fixture-at'))->utc();
            } catch (\Throwable) {
                $this->error('Provide a valid ISO-8601 fixture instant.');

                return self::FAILURE;
            }
            if ($plan->fixture_at && $date->lt($plan->fixture_at)) {
                $this->error('Fixture time cannot move backwards.');

                return self::FAILURE;
            }
            $plan->update(['fixture_at' => $date]);
        }
        $q->limit(config('operations.tick_limit'))->get()->chunk(50)->each(function ($plans) use ($action): void {
            foreach ($plans as $plan) {
                try {
                    $action->tick($plan);
                    $plan->touch();
                } catch (ValidationException $e) {
                    $action->halt($plan, 'stopped', implode(' ', array_merge(...array_values($e->errors()))));
                }
            }
        });
        $this->info('Approved plans checked. Due logical stages claimed once; sending uses the existing mail worker.');

        return self::SUCCESS;
    }
}
