<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreStaffRequest;
use App\Http\Requests\Store\UpdateStaffRequest;
use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Support\BranchScope;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    /**
     * A staff account is either a plain 'staff' or a 'branch_manager' at the
     * platform-role level — never both — so checks elsewhere in the app that
     * do `hasRole('staff') && !hasRole('branch_manager')` stay correct.
     */
    private function syncPlatformRole(User $user, bool $isBranchManager): void
    {
        $targetRole = Role::where('name', $isBranchManager ? 'branch_manager' : 'staff')->first();
        $otherRole = Role::where('name', $isBranchManager ? 'staff' : 'branch_manager')->first();

        if ($otherRole) {
            $user->roles()->detach($otherRole->id);
        }

        if ($targetRole && ! $user->roles()->where('roles.id', $targetRole->id)->exists()) {
            $user->roles()->attach($targetRole->id);
        }
    }

    /**
     * A branch manager can create/edit/deactivate staff — that's routine
     * headcount management, not something that should always have to route
     * through the owner — but strictly within their own branch, and never
     * touching the branch-manager tier itself: promoting someone to
     * (or editing/removing an existing) branch manager stays owner-only,
     * same as the store owner never being editable through this controller.
     * Returns a 403 JsonResponse to short-circuit on, or null to proceed.
     */
    private function staffManagerCrudDenied(Request $request, int $targetBranchId, bool $wantsBranchManager, ?StaffProfile $existingTarget = null): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('store_owner')) {
            return null;
        }

        if ($wantsBranchManager) {
            return response()->json(['success' => false, 'message' => 'Only the shop owner can promote a staff member to branch manager.'], 403);
        }

        if ($existingTarget && $existingTarget->is_branch_manager) {
            return response()->json(['success' => false, 'message' => 'Only the shop owner can edit or remove another branch manager.'], 403);
        }

        $callerBranchId = $user->staffProfile?->store_branch_id ?? null;
        if (! $callerBranchId || (int) $callerBranchId !== $targetBranchId) {
            return response()->json(['success' => false, 'message' => 'You can only manage staff within your own branch.'], 403);
        }

        return null;
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        // A branch manager or staff account pinned to a branch only ever sees
        // that branch's own roster — matches the same per-action branch check
        // already enforced on job orders (JobOrderController::branchAccessDenied)
        // and its list-scoping (JobOrderController::index). Previously this
        // returned every branch's staff regardless of who was asking. Filtered
        // after the cache read/write below (not baked into the cache key) so
        // store()/update()/destroy()'s single forget() call still invalidates
        // every caller's view correctly.
        // The owner can also narrow it to the branch picked in the switcher
        // (BranchScope); "All Branches" passes no branch_id.
        $scopedBranchId = BranchScope::resolve($request, $store);
        // ?include_floating=1 (assign-staff dropdowns): also the staff with no
        // branch, who work across all branches.
        $includeFloating = $request->boolean('include_floating');
        $inScope = fn ($s) => (int) ($s['store_branch_id'] ?? 0) === (int) $scopedBranchId
            || ($includeFloating && empty($s['store_branch_id']));

        $cacheKey = "store_staff_{$store->id}";
        $cached = app()->environment('testing') ? null : Cache::driver('file')->get($cacheKey);
        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
            $data = $scopedBranchId
                ? array_values(array_filter($cached['data'], $inScope))
                : $cached['data'];

            return response()->json(['success' => true, 'data' => $data]);
        }

        $staff = $store->staff()->with(['user:id,name,email,phone,last_seen_at,profile_picture', 'branch:id,name'])->get();

        // One grouped query for all staff instead of 2 queries per staff
        // member — previously scaled linearly with the store's headcount.
        $userIds = $staff->pluck('user_id');
        // COUNT(DISTINCT job_order_id), not a raw row SUM — job_order_staff
        // has one row per production STAGE, and a staff member is routinely
        // assigned to several stages of the same job order (e.g. design +
        // pattern_making + cutting, all still incomplete). A raw row count
        // over-reports "active jobs" for exactly that staff member: verified
        // live, one staff member with 2 open stage-rows on the same job
        // order showed "2 active jobs" for what was really only 1 actual
        // job. The whole point of this number is "how many distinct jobs is
        // this person on right now," not "how many stage-assignments."
        $counts = DB::table('job_order_staff')
            ->whereIn('user_id', $userIds)
            ->selectRaw('
                user_id,
                COUNT(DISTINCT CASE WHEN completed_at IS NULL THEN job_order_id END) as active_jobs,
                COUNT(DISTINCT CASE WHEN completed_at IS NOT NULL THEN job_order_id END) as completed_jobs
            ')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $staff->transform(function ($s) use ($counts) {
            $row = $counts->get($s->user_id);
            $s->active_jobs = (int) ($row->active_jobs ?? 0);
            $s->completed_jobs = (int) ($row->completed_jobs ?? 0);

            return $s;
        });

        $fullData = $staff->values()->toArray();
        $payload = ['success' => true, 'data' => $fullData];

        if (! app()->environment('testing')) {
            Cache::driver('file')->put($cacheKey, $payload, 30);
        }

        $data = $scopedBranchId
            ? array_values(array_filter($fullData, $inScope))
            : $fullData;

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(Request $request, Store $store, StaffProfile $staff): JsonResponse
    {
        if ($staff->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $user = $request->user();
        $scopedBranchId = ! $user->hasRole('store_owner') ? ($user->staffProfile?->store_branch_id ?? null) : null;
        if ($scopedBranchId && $staff->store_branch_id && (int) $scopedBranchId !== (int) $staff->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'This staff member belongs to a different branch.'], 403);
        }

        // Work-history log: every job this staff was assigned to (assigned vs completed).
        $assignments = DB::table('job_order_staff as jos')
            ->join('job_orders as jo', 'jo.id', '=', 'jos.job_order_id')
            ->leftJoin('users as c', 'c.id', '=', 'jo.customer_id')
            ->where('jos.user_id', $staff->user_id)
            ->where('jo.store_id', $store->id)
            ->orderByDesc('jos.assigned_at')
            ->get([
                'jos.job_order_id',
                'jo.order_number',
                'jo.status as job_status',
                'jos.stage',
                'jos.assigned_at',
                'jos.completed_at',
                'c.name as customer_name',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'staff' => $staff->load(['user:id,name,email,phone,last_seen_at,profile_picture', 'branch:id,name']),
                // total_assigned/total_completed intentionally stay as raw
                // stage-assignment counts — they correspond 1:1 with the
                // per-stage `assignments` log rendered below. 'active' is
                // the one figure meant to answer "how many distinct jobs,"
                // same question the Staff List's Active Jobs column
                // answers — so it needs the same distinct-job-order fix
                // (see StaffController::index), or the two "Active" numbers
                // for the same person would silently disagree.
                'total_assigned' => $assignments->count(),
                'total_completed' => $assignments->whereNotNull('completed_at')->count(),
                'active' => $assignments->whereNull('completed_at')->pluck('job_order_id')->unique()->count(),
                'assignments' => $assignments,
            ],
        ]);
    }

    public function store(StoreStaffRequest $request, Store $store): JsonResponse
    {
        // A branch manager can hire/manage staff — day-to-day headcount is
        // their call, not something that should have to route through the
        // owner every time — but only within their own branch, and they can
        // never mint another branch manager (that's a supervisory promotion,
        // owner-only, same reasoning as StaffProfile::is_branch_manager
        // being owner-gated everywhere else it's touched).
        if ($denied = $this->staffManagerCrudDenied($request, (int) $request->store_branch_id, $request->boolean('is_branch_manager'))) {
            return $denied;
        }

        // SubscriptionPlan::max_staff is configurable per plan (unlike the
        // branch limit, which is a flat premium-only gate) but was never
        // actually checked here — a store on the cheapest plan could hire an
        // unlimited staff roster with no enforcement at all.
        $subscription = $store->subscription()->whereIn('status', ['active', 'trial'])->first();
        // A store with no active/trial subscription at all must fall back to
        // the cheapest plan's cap, not skip the gate entirely — treating a
        // missing subscription as "unlimited" let a store with literally no
        // plan hire more staff than a paying Basic subscriber, the opposite
        // of StoreBranchController's equivalent gate, which already fails
        // closed the same way (no subscription => no premium perks).
        $maxStaff = $subscription?->plan?->max_staff ?? SubscriptionPlan::where('slug', 'basic')->value('max_staff') ?? 1;
        // -1 is this table's documented "unlimited" sentinel (see
        // SubscriptionPlanSeeder) — not a real cap to compare against.
        if ($maxStaff !== -1 && $store->staff()->count() >= $maxStaff) {
            return response()->json([
                'success' => false,
                'message' => "Your plan allows up to {$maxStaff} staff member".($maxStaff === 1 ? '' : 's').'. Upgrade your plan to add more.',
            ], 403);
        }

        $existing = User::where('email', $request->email)->first();

        if ($existing && $existing->password_set_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This email already belongs to a registered account.',
                'errors' => ['email' => ['This email already belongs to a registered account.']],
            ], 422);
        }

        // password_set_at alone doesn't catch every real identity — a
        // walk-in customer created via CustomerController::store() never
        // gets that field set at all, so this email could belong to a real,
        // named customer (with real order/appointment history) even though
        // the check above passed. Confirmed live: hiring "staff" with an
        // existing customer's email silently overwrote their name and
        // enrolled them as an employee. Block on any sign this identity is
        // already a known customer, of this store or any other.
        if ($existing) {
            $isKnownCustomer = $existing->hasRole('customer')
                || DB::table('store_customers')->where('user_id', $existing->id)->exists()
                || JobOrder::where('customer_id', $existing->id)->exists()
                || Appointment::where('customer_id', $existing->id)->exists();

            if ($isKnownCustomer) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email belongs to an existing customer and cannot be used for a staff account.',
                    'errors' => ['email' => ['This email belongs to an existing customer and cannot be used for a staff account.']],
                ], 422);
            }
        }

        if ($existing) {
            $user = $existing;
            $user->update([
                'name' => $request->name,
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

        $isBranchManager = $request->boolean('is_branch_manager');
        $this->syncPlatformRole($user, $isBranchManager);

        $staff = $store->staff()->create([
            'user_id' => $user->id,
            'role' => $request->role,
            'additional_roles' => $request->additional_roles,
            'specialization' => $request->specialization,
            'hired_at' => $request->hired_at,
            'store_branch_id' => $request->store_branch_id,
            'is_branch_manager' => $isBranchManager,
            'bio' => $request->bio,
        ]);

        Cache::driver('file')->forget("store_staff_{$store->id}");

        return response()->json([
            'success' => true,
            'data' => $staff->load(['user:id,name,email,phone,last_seen_at,profile_picture', 'branch:id,name']),
        ], 201);
    }

    public function update(UpdateStaffRequest $request, Store $store, StaffProfile $staff): JsonResponse
    {
        if ($staff->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $targetBranchId = $request->has('store_branch_id') ? (int) $request->store_branch_id : $staff->store_branch_id;
        if ($denied = $this->staffManagerCrudDenied($request, $targetBranchId, $request->boolean('is_branch_manager'), $staff)) {
            return $denied;
        }

        // Update the associated User
        $user = $staff->user;
        if ($user) {
            $userData = $request->only(['name', 'email', 'phone']);
            if ($request->filled('password')) {
                $userData['password'] = Hash::make($request->password);
            }
            $user->update($userData);
        }

        // Update the StaffProfile
        $staff->update($request->only(['role', 'additional_roles', 'specialization', 'hired_at', 'is_active', 'store_branch_id', 'is_branch_manager', 'bio', 'is_available']));

        if ($user && $request->has('is_branch_manager')) {
            $this->syncPlatformRole($user, $staff->is_branch_manager);
        }

        Cache::driver('file')->forget("store_staff_{$store->id}");

        return response()->json([
            'success' => true,
            'data' => $staff->load(['user:id,name,email,phone,last_seen_at,profile_picture', 'branch:id,name']),
        ]);
    }

    public function destroy(Request $request, Store $store, StaffProfile $staff): JsonResponse
    {
        if ($staff->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->staffManagerCrudDenied($request, (int) $staff->store_branch_id, false, $staff)) {
            return $denied;
        }

        // Only remove the StaffProfile — the roster entry — and leave the
        // underlying User row alone. Deleting the User here used to cascade
        // into every historical record that references them by user id
        // (JobOrder::assignedStaff, Payment::recordedBy/rejectedBy,
        // AuditLog::user), silently blanking out "who did this" across the
        // store's entire history the moment a staff member was offboarded —
        // a routine action, not a reason to lose past attribution.
        //
        // Logged before delete() (the StaffProfile itself, not the User row,
        // so name/email still need to be captured now) — removing a staff
        // member is exactly the kind of action the owner needs to see in
        // the Audit Log for a branch_manager's own actions, and previously
        // there was no record it happened at all.
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'staff_removed',
            'model_type' => StaffProfile::class,
            'model_id' => $staff->id,
            'payload' => [
                'name' => $staff->user?->name,
                'role' => $staff->role,
            ],
            'ip_address' => $request->ip(),
        ]);

        $staff->delete();

        Cache::driver('file')->forget("store_staff_{$store->id}");

        return response()->json([
            'success' => true,
            'message' => 'Staff member removed.',
        ]);
    }
}
