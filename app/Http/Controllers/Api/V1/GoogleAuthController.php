<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * "Continue with Google" for customers. The API is token-based (no session),
 * so the round trip works like this:
 *
 * 1. redirect(): the browser is sent to Google with a one-time `state` we
 *    keep in the cache (Socialite's own state check needs a session).
 * 2. callback(): Google sends the browser back here; we check the state,
 *    find or create the customer, and send the browser to the web app's
 *    /auth/google/callback with a one-time code in the URL fragment.
 * 3. exchange(): the web app posts that code and gets the same payload as
 *    a normal sign-in. The code works once and only for two minutes, so the
 *    token itself never appears in a URL.
 *
 * Shop accounts (owner, branch manager, staff) and admins can't use it:
 * they sign in with their shop login on the Shop tab.
 */
class GoogleAuthController extends Controller
{
    private const STATE_TTL = 600;

    private const CODE_TTL = 120;

    public function redirect(): RedirectResponse
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return $this->backToApp(['error' => 'Google sign-in isn\'t set up yet. Please sign in with your email and password.']);
        }

        $state = Str::random(40);
        Cache::put("google_oauth_state_{$state}", true, self::STATE_TTL);

        return Socialite::driver('google')
            ->stateless()
            ->with(['state' => $state, 'prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        if ($state === '' || ! Cache::pull("google_oauth_state_{$state}")) {
            return $this->backToApp(['error' => 'That Google sign-in link expired. Please try again.']);
        }
        if ($request->filled('error')) {
            return $this->backToApp(['error' => 'Google sign-in was cancelled.']);
        }

        try {
            $google = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            Log::warning('Google sign-in failed', ['error' => $e->getMessage()]);

            return $this->backToApp(['error' => 'We couldn\'t sign you in with Google. Please try again.']);
        }

        $email = Str::lower(trim((string) $google->getEmail()));
        // Only a Google-verified address may claim an account with that email.
        if ($email === '' || ! ($google->user['email_verified'] ?? $google->user['verified_email'] ?? false)) {
            return $this->backToApp(['error' => 'Your Google account email isn\'t verified, so we can\'t use it to sign in.']);
        }

        $user = User::where('google_id', $google->getId())->first()
            ?? User::where('email', $email)->first();

        if ($user) {
            $roles = $user->roles()->pluck('name');
            $isShopSide = $roles->intersect(['admin', 'store_owner', 'branch_manager', 'staff'])->isNotEmpty();
            if ($isShopSide && ! $roles->contains('customer')) {
                return $this->backToApp(['error' => 'This is a shop account. Use the Shop tab and sign in with your shop login.']);
            }
            if ($user->suspended_at !== null) {
                return $this->backToApp(['error' => 'This account has been suspended. Please contact SUTURA support.']);
            }

            // Linking also claims a guest-booking "shadow" account (no real
            // password yet): Google has just proved the email belongs to
            // this person, and marking it set stops anyone else from
            // claiming it later through the register form.
            $user->forceFill([
                'google_id' => $google->getId(),
                'password_set_at' => $user->password_set_at ?? now(),
                'profile_picture' => $user->profile_picture ?: $google->getAvatar(),
            ])->save();
            $event = ['signed_in', 'Signed in with Google'];
        } else {
            $user = User::create([
                'name' => $google->getName() ?: Str::before($email, '@'),
                'email' => $email,
                // They sign in with Google; "Forgot Password?" sets a real one.
                'password' => Hash::make(Str::random(40)),
                'password_set_at' => now(),
                'profile_picture' => $google->getAvatar(),
            ]);
            $user->forceFill(['google_id' => $google->getId()])->save();
            $event = ['account_registered', 'Created an account with Google'];
        }

        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole && ! $user->hasRole('customer')) {
            $user->roles()->attach($customerRole->id);
        }

        ActivityRecorder::forUser($request, $user, $event[0], $event[1]);

        $code = Str::random(64);
        Cache::put("google_oauth_code_{$code}", $user->id, self::CODE_TTL);

        return $this->backToApp(['code' => $code]);
    }

    /** Trades the one-time code for a sign-in token (same payload as AuthController::login). */
    public function exchange(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:64']]);

        $userId = Cache::pull('google_oauth_code_'.$request->input('code'));
        $user = $userId ? User::find($userId) : null;
        if (! $user || $user->suspended_at !== null) {
            return response()->json(['success' => false, 'message' => 'That Google sign-in expired. Please try again.'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user->load('roles:id,name'),
                'staff_profile' => null,
                'store' => null,
                'token' => $token,
            ],
        ]);
    }

    /** Back to the web app's callback page; values ride in the URL fragment, which browsers never send to a server. */
    private function backToApp(array $params): RedirectResponse
    {
        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/auth/google/callback#'.http_build_query($params));
    }
}
