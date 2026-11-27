<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    /**
     * Live check for the customer Create Account form. Same rule register()
     * enforces: an account that already has a password is taken; a walk-in or
     * guest placeholder (no password yet) is available, because signing up
     * claims it. Shop logins can't be used for a customer account.
     * Rate-limited so it can't be used to probe emails in bulk.
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:191']]);
        $email = trim($validated['email']);

        $status = 'available';
        if (preg_match('/@'.preg_quote(\App\Support\ShopLogin::domain(), '/').'$/i', $email)) {
            $status = 'shop_login';
        } elseif (User::where('email', $email)->whereNotNull('password_set_at')->exists()) {
            $status = 'taken';
        }

        return response()->json(['success' => true, 'data' => ['email' => $status]]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $existing = User::where('email', $request->email)->first();

        // An existing row with no real password yet is a "shadow" account —
        // auto-created by a guest booking with a random, never-communicated
        // password. Let this registration claim it instead of bouncing on a
        // false-positive duplicate-email error; a fully-claimed account
        // (owner, staff, or an already-registered customer) is still blocked.
        if ($existing && $existing->password_set_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already registered. Please log in instead.',
                'errors' => ['email' => ['This email is already registered.']],
            ], 422);
        }

        // If no account exists with this email, check if an unclaimed walk-in
        // shadow account was created at the store counter matching this phone
        // number with a synthetic email (walkin_%@sutura.com):
        if (! $existing && $request->filled('phone')) {
            $existing = User::where('phone', $request->phone)
                ->where('email', 'like', 'walkin_%@sutura.com')
                ->whereNull('password_set_at')
                ->first();
        }

        if ($existing) {
            $user = $existing;
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
                // Their own sign-up email is now where updates go, not the
                // (possibly shared) address staff typed for them as a walk-in.
                'contact_email' => null,
                'password' => Hash::make($request->password),
                'password_set_at' => now(),
                'phone' => $request->phone ?? $user->phone,
            ]);
        } else {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'password_set_at' => now(),
                'phone' => $request->phone,
            ]);
        }

        $role = Role::where('name', $request->role)->first();
        if ($role && ! $user->hasRole($role->name)) {
            $user->roles()->attach($role->id);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        ActivityRecorder::forUser($request, $user, $existing ? 'account_activated' : 'account_registered', $existing ? 'Activated their account' : 'Created an account');

        return response()->json([
            'success' => true,
            'message' => $existing ? 'Account activated — your previous booking history is now linked to this account.' : 'Account created successfully.',
            'data' => [
                'user' => $user->load('roles:id,name'),
                'token' => $token,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $roles = $user->roles()->pluck('name');

        // System Admins sign in only through the separate admin portal
        // (Admin\AdminAuthController). Same generic message as a wrong
        // password, so this public form can't be used to learn which
        // emails belong to admins.
        if ($roles->contains('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->suspended_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been suspended. Please contact SUTURA support.',
            ], 403);
        }

        if ($mismatch = $this->portalMismatch($request->input('portal'), $roles->all())) {
            return response()->json(['success' => false, 'message' => $mismatch], 422);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        ActivityRecorder::forUser($request, $user, 'signed_in', 'Signed in');

        $user->load('roles:id,name', 'stores');

        $staffProfile = null;
        if ($user->hasRole('staff') || $user->hasRole('branch_manager')) {
            $user->load(['staffProfile.store', 'staffProfile.branch']);
            if ($user->staffProfile) {
                $staffProfile = $user->staffProfile;
            }
        }

        $store = $user->stores->first() ?? $staffProfile?->store;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'staff_profile' => $staffProfile,
                'store' => $store,
                'token' => $token,
            ],
        ]);
    }

    /**
     * The Sign In page's Customer / Shop Owner switch. Only checked after
     * the password is verified, so it reveals nothing to a guesser; it just
     * points a real user at the tab their account actually belongs to.
     */
    private function portalMismatch(?string $portal, array $roles): ?string
    {
        $isStoreSide = array_intersect($roles, ['store_owner', 'branch_manager', 'staff']) !== [];
        $isCustomer = in_array('customer', $roles, true);

        if ($portal === 'customer' && ! $isCustomer && $isStoreSide) {
            return 'This is a shop account. Switch to the Shop tab to sign in.';
        }
        if ($portal === 'store' && ! $isStoreSide) {
            return 'This is a customer account. Switch to the Customer tab to sign in.';
        }

        return null;
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        // Always return success, whether or not the email exists — this
        // stops login-form scraping from being repurposed to enumerate
        // registered store-owner emails.
        return response()->json([
            'success' => true,
            'message' => 'If that email is registered, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // Choosing a password via a reset link also satisfies an
                // admin-issued temporary password's forced change.
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                ])->save();
                ActivityRecorder::forUser(request(), $user, 'password_reset', 'Reset their password with an email link');
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => 'This reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.',
        ]);
    }

    /**
     * A new browser tab opened from a signed-in SUTURA tab ("Open link in new
     * tab") borrows that tab's login (lib/tabSession.ts) and trades the copied
     * token for one of its own here. Each tab then signs out on its own: logout
     * deletes only that tab's token, never the tab it was opened from.
     * Same user, same name and abilities (an admin token stays admin-only).
     */
    public function tabToken(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $token = $request->user()->createToken($current->name ?? 'auth_token', $current->abilities ?? ['*'])->plainTextToken;

        return response()->json(['success' => true, 'data' => ['token' => $token]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles:id,name', 'stores');

        $staffProfile = null;
        if ($user->hasRole('staff') || $user->hasRole('branch_manager')) {
            $user->load(['staffProfile.store', 'staffProfile.branch']);
            if ($user->staffProfile) {
                $staffProfile = $user->staffProfile;
            }
        }

        $store = $user->stores->first() ?? $staffProfile?->store;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'staff_profile' => $staffProfile,
                'store' => $store,
            ],
        ]);
    }
}
