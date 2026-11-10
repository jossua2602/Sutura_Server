<?php

namespace App\Console\Commands;

use App\Models\StoreSubscription;
use App\Support\SubscriptionSwitcher;
use Illuminate\Console\Command;

/**
 * Downgrades wait for the current paid period to end (see
 * SubscriptionSwitcher::schedule). This switches them on time; a paid one
 * only if its payment was approved — otherwise the plan runs out as usual.
 */
class ApplyScheduledPlanChanges extends Command
{
    protected $signature = 'app:apply-scheduled-plan-changes';

    protected $description = 'Switches shops to the plan they scheduled once their current period ends.';

    public function handle(): int
    {
        $applied = 0;
        StoreSubscription::whereIn('status', ['active', 'trial'])
            ->whereNotNull('scheduled_plan_id')
            ->where('ends_at', '<=', now())
            ->with(['store', 'scheduledPlan', 'scheduledPayment'])
            ->each(function (StoreSubscription $sub) use (&$applied) {
                if (SubscriptionSwitcher::applyScheduled($sub)) {
                    $applied++;
                }
            });

        $this->info("Applied {$applied} scheduled plan change(s).");

        return self::SUCCESS;
    }
}
