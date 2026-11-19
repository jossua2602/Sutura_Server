<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform account list + suspend/reactivate (sutura2's AccountsView).
 * Deliberately no role editing: roles here are structural (a store_owner
 * owns stores, staff hang off a staff profile), so flipping the string the
 * way the prototype did would orphan that data. Admins can't be suspended
 * from here at all — that includes suspending yourself.
 */
class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->with('roles:id,name')
            ->select(['id', 'name', 'email', 'phone', 'created_at', 'last_seen_at', 'suspended_at'])
            ->latest();

        if ($search = trim((string) $request->input('search'))) {
            $term = '%'.strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term]));
        }
        if ($role = $request->input('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }
        if ($request->input('status') === 'suspended') {
            $query->whereNotNull('suspended_at');
        } elseif ($request->input('status') === 'active') {
            $query->whereNull('suspended_at');
        }

        // Page size: ?per_page (5–50), default 25. The admin Accounts page asks for 10.
        $page = $query->paginate(max(5, min(50, (int) $request->input('per_page', 25))));

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        if ($user->hasRole('admin')) {
            return response()->json(['success' => false, 'message' => 'System Admin accounts cannot be suspended here.'], 422);
        }

        $user->forceFill(['suspended_at' => now()])->save();
        // Sign them out everywhere now, not whenever their token expires.
        $user->tokens()->delete();

        AdminAudit::record($request, 'account_suspended', User::class, $user->id, $user->email, reason: $validated['reason']);

        return response()->json(['success' => true, 'message' => "{$user->name} has been suspended.", 'data' => $user->fresh('roles:id,name')]);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $user->forceFill(['suspended_at' => null])->save();

        AdminAudit::record($request, 'account_reactivated', User::class, $user->id, $user->email);

        return response()->json(['success' => true, 'message' => "{$user->name} can sign in again.", 'data' => $user->fresh('roles:id,name')]);
    }
}
