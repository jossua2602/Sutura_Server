<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // In-app notifications record the branch they are about (notifications.branch_id).
        $this->app->bind(
            \Illuminate\Notifications\Channels\DatabaseChannel::class,
            \App\Notifications\Channels\BranchAwareDatabaseChannel::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce 191-character default string length so MariaDB / MySQL on XAMPP
        // does not exceed the 1000-byte index limit on utf8mb4 unique/primary keys.
        Schema::defaultStringLength(191);

        // Point password-reset emails at the Next.js reset page instead of
        // Laravel's default backend-only URL — there is no server-rendered
        // reset view in this API-only app.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($notifiable->getEmailForPasswordReset());
        });

        // Single source of truth for "what counts as a strong password" —
        // Password::defaults() is already referenced by ProfileController's
        // password-change validation; before this it fell back to Laravel's
        // bare min(8) with no complexity requirement. Registration
        // (RegisterRequest) now uses this same rule instead of its own
        // plain 'min:8', so the frontend's password checklist (8+ chars,
        // number+symbol, upper+lowercase) matches what the server actually
        // enforces everywhere a password is set, not just decorative UI copy.
        // Every signed-in API request counts against one generous per-account budget (the
        // dashboard polls and fans out, so this is far above normal use) — it exists to stop a
        // stolen token or a runaway script, not to shape traffic.
        RateLimiter::for('authed', fn (Request $r) => Limit::perMinute(300)->by('u:'.($r->user()?->id ?? $r->ip())));
        // Anything that writes a file or calls out: tight, per account (or IP when anonymous).
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(20)->by('up:'.($r->user()?->id ?? $r->ip())));
        // Money and message writes a customer can fire repeatedly.
        RateLimiter::for('sensitive-writes', fn (Request $r) => Limit::perMinute(12)->by('sw:'.($r->user()?->id ?? $r->ip())));

        Password::defaults(function () {
            return Password::min(8)->mixedCase()->numbers()->symbols();
        });
    }
}
