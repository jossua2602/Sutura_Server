<?php

namespace App\Http\Middleware;

use App\Models\Store;
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
                'message' => 'Unauthorized. Insufficient permissions.',
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
                    'message' => 'Store not found.',
                ], 404);
            }

            $belongsToStore = $user->hasRole('store_owner')
                ? $store->owner_id === $user->id
                : $user->staffProfile?->store_id === $store->id;

            if (! $belongsToStore) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not belong to this store.',
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
        }

        return $next($request);
    }
}
