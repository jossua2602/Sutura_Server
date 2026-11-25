<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-wide activity log (sutura2's AuditLogView): every action by every
 * role (see App\Support\ActivityRecorder), filterable by role and shop. The
 * per-store owner view (Api\V1\AuditLogController) stays scoped to its own store_id.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with(['user:id,name,email', 'store:id,name'])->latest();

        if ($search = trim((string) $request->input('search'))) {
            $term = '%'.strtolower($search).'%';
            // Not the JSON payload: CAST(... AS CHAR) is MySQL-only (it
            // means char(1) on Postgres, where this is headed).
            $query->where(fn ($q) => $q->whereRaw('LOWER(action) LIKE ?', [$term])
                ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term]))
                ->orWhereHas('store', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', [$term])));
        }
        if ($request->input('scope') === 'admin') {
            $query->whereNull('store_id');
        } elseif ($request->input('scope') === 'stores') {
            $query->whereNotNull('store_id');
        }
        // "Tailoring staff" (the Tailoring Staff Module) = every shop worker account: branch managers and staff.
        if (($role = $request->input('role')) === 'tailoring_staff') {
            $query->whereIn('actor_role', ['branch_manager', 'staff']);
        } elseif (in_array($role, [...AuditLog::ROLES, 'guest'], true)) {
            $query->where('actor_role', $role);
        }
        if ($storeId = (int) $request->input('store_id')) {
            $query->where('store_id', $storeId);
        }
        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // The Activity Log page asks for 10; capped so a caller can't pull the whole log at once.
        $page = $query->paginate(min(max((int) $request->input('per_page', 30), 1), 100));

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            // Options for the shop filter — every shop, including ones not approved yet.
            'stores' => Store::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
