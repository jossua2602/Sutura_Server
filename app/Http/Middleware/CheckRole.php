<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\SubscriptionSwitcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->roles()->whereIn('name', $roles)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Your account doesn't have access to this.",
            ], 403);
        }

        // A shop login issued by the System Admin starts with a temporary
        // password; nothing store-scoped works until the owner replaces it
        // (PUT /profile/password — not role-gated, so it stays reachable).
        if ($user->must_change_password) {
            return response()->json([
                'success' => false,
                'code' => 'password_change_required',
                'message' => 'Please change your temporary password before continuing.',
            ], 403);
        }

        // Admin routes intentionally act across every store; skip tenant scoping.
        if (in_array('admin', $roles, true)) {
            return $next($request);
        }

        // Every other role-gated route is scoped to a single store. Verify the
        // authenticated user actually belongs to the {store} in the URL —
        // having the role globally is not enough, otherwise staff/branch
        // managers of one store could read or modify another store's data.
        //
        // Some controllers accept the route segment as a raw id (e.g. `$storeId`)
        // instead of type-hinting `Store $store`, so implicit route-model binding
        // never runs for them. Resolve it manually in that case rather than
        // skipping the check.
        $storeParam = $request->route('store');
        if ($storeParam !== null) {
            $store = $storeParam instanceof Store ? $storeParam : Store::find($storeParam);

            if (! $store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shop not found.',
                ], 404);
            }

            $belongsToStore = $user->hasRole('store_owner')
                ? $store->owner_id === $user->id
                : $user->staffProfile?->store_id === $store->id;

            if (! $belongsToStore) {
                return response()->json([
                    'success' => false,
                    'message' => "You don't have access to this shop.",
                ], 403);
            }

            // A staff account locked by the shop's plan (App\Support\PlanLimits)
            // can still sign in and look, but not change anything.
            if (! $user->hasRole('store_owner') && $user->staffProfile?->plan_locked_at
                && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                return response()->json([
                    'success' => false,
                    'code' => 'plan_locked',
                    'message' => "Your account is view-only: the shop's plan doesn't include it right now. Ask the shop owner.",
                ], 403);
            }

            // A live shop with no running plan (it ran out, was cancelled, or a
            // trial ended unpaid) is read-only for everyone in it: every record
            // stays viewable and exportable, nothing new or changed until it pays.
            // Billing stays open so it can pay, and Support so it can ask for help.
            if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
                && $store->status === 'approved'
                && ! $this->openWhileReadOnly($request)
                && SubscriptionSwitcher::current($store) === null) {
                return response()->json([
                    'success' => false,
                    'code' => 'shop_read_only',
                    'message' => $user->hasRole('store_owner')
                        ? 'Your shop is read-only because its plan has ended. Choose a plan in Billing to make changes again.'
                        : "This shop is read-only because its plan has ended. Ask the shop owner to renew it.",
                ], 403);
            }
        }

        return $next($request);
    }

    /** Routes a read-only shop can still use: paying for a plan (Billing) and Support tickets. */
    private function openWhileReadOnly(Request $request): bool
    {
        $uri = (string) $request->route()?->uri();

        return str_contains($uri, '{store}/subscription') || str_contains($uri, '{store}/tickets');
    }
}
