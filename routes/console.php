<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Matches the thesis's own non-functional requirement: "execute automated
// database backups every 24 hours."
Schedule::command('app:backup-database')->daily();

// Turns the passive overdue_jobs dashboard KPI into a proactive daily alert —
// most useful exactly when an owner is too busy (peak season) to remember to
// check the dashboard themselves.
Schedule::command('app:notify-overdue-jobs')->dailyAt('08:00');

// REQUIREMENTS.md Phase 5: "Expired subscriptions automatically downgrade
// store visibility to Hidden until renewed."
// Downgrades queued for the end of a paid period, switched on time (hourly);
// expire-subscriptions also runs them first, so a queued change never expires.
Schedule::command('app:apply-scheduled-plan-changes')->hourly();
Schedule::command('app:expire-subscriptions')->daily();

// Warns before that happens — "maintain active subscription validity for
// continued platform visibility" is one of the thesis's own specific
// objectives, which a post-hoc-only notification doesn't actually serve.
Schedule::command('app:remind-expiring-subscriptions')->daily();

// The "customer forgot their fitting" pain point named directly in the
// tailoring-store interview research — hourly so every appointment passes
// through the ~24h-ahead window once; reminder_sent_at prevents duplicates.
Schedule::command('app:remind-upcoming-appointments')->hourly();

// Same "passive KPI into a proactive alert" pattern as notify-overdue-jobs,
// for garments sitting unclaimed on the rack 14+ days (see
// AnalyticsController's unclaimed_pickups list / job_orders.ready_for_pickup_at).
Schedule::command('app:notify-unclaimed-pickups')->dailyAt('08:00');

// Same pattern again, for job orders sitting on_hold 7+ days — on_hold jobs
// are correctly excluded from the overdue_jobs KPI (paused on purpose, not
// "late"), but that left them with zero proactive visibility at all.
Schedule::command('app:notify-jobs-on-hold')->dailyAt('08:00');

// Privacy retention: shop-application selfies are deleted 30 days after the
// application is approved or rejected (promised in the consent text).
Schedule::command('app:purge-application-selfies')->dailyAt('03:00');
