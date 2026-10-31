<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\BranchScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    private const SUTURA_DOMAIN = '@sutura.com';

    private const MSG_NOT_FOUND = 'Customer not found in this store';

    public function index(Request $request, Store $store): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // A branch (picked by the owner, or the one a manager/staff account is
        // pinned to) shows only customers with an order or appointment there,
        // and their totals count that branch only. null = all branches.
        $branchId = BranchScope::resolve($request, $store);
        $inBranch = fn ($q) => $branchId ? $q->where('store_branch_id', $branchId) : $q;

        // Get customers from pivot, job orders, AND appointments
        $jobCustomerIds = $inBranch($store->jobOrders())->pluck('customer_id')->toArray();
        $apptCustomerIds = $inBranch($store->appointments())->pluck('customer_id')->toArray();
        // Customers added through "Add Customer" carry no branch. They show in
        // the consolidated view and on the Main Shop (the shop's home branch),
        // never on another branch.
        $mainBranchId = (int) $store->branches()->where('is_main', true)->value('id');
        $pivotCustomerIds = ! $branchId || $branchId === $mainBranchId
            ? $store->customers()->pluck('users.id')->toArray()
            : [];

        $customerIds = collect(array_merge($jobCustomerIds, $pivotCustomerIds, $apptCustomerIds))->filter()->unique();

        // Server-side search filter across name, email, and contact number
        $search = trim($request->input('search', ''));
        if ($search !== '') {
            $matchingUserIds = User::where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })->whereDoesntHave('roles', function ($q) {
                $q->whereIn('name', ['store_owner', 'staff', 'branch_manager', 'admin']);
            })->pluck('id')->toArray();

            // Search only narrows within this branch's customers; it never
            // pulls in customers who have never been to this branch.
            $customerIds = $branchId ? $customerIds : $customerIds->merge($matchingUserIds)->unique();
        }

        // "My customers": the ones with an appointment assigned to me or a job I'm on.
        if ($request->boolean('mine')) {
            $me = $request->user()->id;
            $mine = $store->appointments()->where('assigned_staff_id', $me)->pluck('customer_id')
                ->merge($store->jobOrders()->where(function ($q) use ($me) {
                    $q->where('assigned_staff_id', $me)
                        ->orWhereIn('job_orders.id', \Illuminate\Support\Facades\DB::table('job_order_staff')->where('user_id', $me)->select('job_order_id'));
                })->pluck('customer_id'));
            $customerIds = $customerIds->intersect($mine->unique())->values();
        }

        // Store-specific notes live on the store_customers pivot, not the User
        // row — fetched once here (keyed by user id) rather than per-customer,
        // same N+1-avoidance reasoning as the jobOrders/appointments eager loads.
        $notesByCustomerId = $store->customers()->pluck('store_customers.notes', 'users.id');

        $customersQuery = User::whereIn('id', $customerIds);
        if ($search !== '') {
            $customersQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $customersQuery
            ->with([
                'jobOrders' => function ($query) use ($store, $inBranch) {
                    $inBranch($query->where('store_id', $store->id));
                },
                // Eager-loaded once for all customers (already ordered so the
                // first element is the most recent) instead of running a
                // fresh query per customer inside the map() below — avoids an
                // N+1 that scaled with the store's total customer count.
                'appointments' => function ($query) use ($store, $inBranch) {
                    $inBranch($query->where('store_id', $store->id))->orderBy('scheduled_at', 'desc');
                },
            ])
            ->get()
            ->map(function ($user) use ($notesByCustomerId) {
                $hasWalkInJobs = $user->jobOrders->where('intake_channel', 'walk_in')->isNotEmpty();
                $hasOnlineJobs = $user->jobOrders->where('intake_channel', 'online')->isNotEmpty();
                $hasWalkInAppts = $user->appointments->where('intake_channel', 'walk_in')->isNotEmpty();
                $isSyntheticWalkIn = Str::startsWith($user->email, 'walkin_');

                $user->total_spend = (float) $user->jobOrders->sum(fn ($j) => (float) $j->total_amount - (float) $j->balance - (float) $j->discount_amount);
                $user->last_appointment = $user->appointments->first();
                $user->active_jobs = $user->jobOrders->whereNotIn('status', ['completed', 'cancelled', 'rejected'])->count();
                $user->completed_jobs = $user->jobOrders->where('status', 'completed')->count();
                $user->store_notes = $notesByCustomerId[$user->id] ?? null;
                $user->is_walk_in = $isSyntheticWalkIn || ($hasWalkInJobs && ! $hasOnlineJobs);
                $user->intake_channel = $user->is_walk_in ? 'walk_in' : 'online';

                return $user;
            });

        return response()->json([
            'success' => true,
            'data' => collect($customers)->sortByDesc('total_spend')->values(),
        ]);
    }

    public function show(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify customer belongs to store
        $isCustomerOfStore = $store->customers()->where('user_id', $customer->id)->exists()
            || $store->jobOrders()->where('customer_id', $customer->id)->exists()
            || $store->appointments()->where('customer_id', $customer->id)->exists();

        if (! $isCustomerOfStore) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        $storeNotes = $store->customers()->where('user_id', $customer->id)->value('store_customers.notes');

        \App\Support\JobMeasurementSync::syncForCustomer($store, $customer->id);

        $measurements = $store->measurements()
            ->where('customer_id', $customer->id)
            ->with('customer:id,name,email')
            ->latest()
            ->get();

        $jobs = $store->jobOrders()
            ->where('customer_id', $customer->id)
            ->with(['service', 'assignedStaff:id,name', 'staffStages', 'payments.recordedBy:id,name', 'branch:id,name'])
            ->latest()
            ->get();

        $appointments = $store->appointments()
            ->where('customer_id', $customer->id)
            ->with(['service:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number'])
            ->orderBy('scheduled_at', 'desc')
            ->get();

        $hasWalkInJobs = $jobs->where('intake_channel', 'walk_in')->isNotEmpty();
        $hasWalkInAppts = $appointments->where('intake_channel', 'walk_in')->isNotEmpty();
        $isSyntheticWalkIn = Str::startsWith($customer->email, 'walkin_');

        $customerData = $customer->toArray();
        $customerData['store_notes'] = $storeNotes;
        $customerData['is_walk_in'] = $isSyntheticWalkIn || ($hasWalkInJobs && $jobs->where('intake_channel', 'online')->isEmpty());
        $customerData['intake_channel'] = $customerData['is_walk_in'] ? 'walk_in' : 'online';
        $customerData['total_spend'] = (float) $jobs->sum(fn ($j) => (float) $j->total_amount - (float) $j->balance - (float) $j->discount_amount);
        $customerData['active_jobs'] = $jobs->whereNotIn('status', ['completed', 'cancelled'])->count();
        $customerData['completed_jobs'] = $jobs->where('status', 'completed')->count();
        $customerData['no_show_count'] = $appointments->where('status', 'no_show')->count();
        $customerData['last_appointment'] = $appointments->first();

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => $customerData,
                'measurements' => $measurements,
                'jobs' => $jobs,
                'appointments' => $appointments,
            ],
        ]);
    }

    public function measurements(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        \App\Support\JobMeasurementSync::syncForCustomer($store, $customer->id);

        $measurements = $store->measurements()
            ->where('customer_id', $customer->id)
            ->with('customer:id,name,email')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $measurements,
        ]);
    }

    public function customerMeasurements(Request $request, User $customer): JsonResponse
    {
        $store = $request->user()->ownedStore ?? $request->user()->staffProfile?->store;
        if (! $store) {
            return response()->json(['message' => 'Store not found'], 404);
        }

        \App\Support\JobMeasurementSync::syncForCustomer($store, $customer->id);

        $measurements = $store->measurements()
            ->where('customer_id', $customer->id)
            ->with('customer:id,name,email')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $measurements,
        ]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:30',
            'suki_tag' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);

        $rawEmail = ! empty($validated['email']) ? trim($validated['email']) : null;
        $rawPhone = ! empty($validated['phone']) ? trim($validated['phone']) : null;
        $digitsPhone = $rawPhone ? preg_replace('/[^0-9]/', '', $rawPhone) : null;
        if ($digitsPhone && str_starts_with($digitsPhone, '63') && strlen($digitsPhone) === 12) {
            $digitsPhone = '0'.substr($digitsPhone, 2);
        }

        $matchedUser = null;

        // 1. Match by email if a real email address is provided
        if ($rawEmail && ! str_starts_with($rawEmail, 'walkin_')) {
            $matchedUser = User::where('email', $rawEmail)->first();
        }

        // 2. Match by contact number if provided
        if (! $matchedUser && $digitsPhone) {
            $existingCustomerIds = collect()
                ->merge($store->customers()->pluck('users.id'))
                ->merge($store->jobOrders()->pluck('customer_id'))
                ->merge($store->appointments()->pluck('customer_id'))
                ->filter()
                ->unique();

            // First search within the current store's customer base
            $matchedUser = User::whereIn('id', $existingCustomerIds)
                ->where(function ($q) use ($rawPhone, $digitsPhone) {
                    $q->where('phone', $rawPhone)
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ?", [$digitsPhone]);
                })
                ->first();

            // If not found in current store, check across customer users in database
            if (! $matchedUser) {
                $matchedUser = User::where(function ($q) use ($rawPhone, $digitsPhone) {
                    $q->where('phone', $rawPhone)
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ?", [$digitsPhone]);
                })->whereDoesntHave('roles', function ($q) {
                    $q->whereIn('name', ['store_owner', 'staff', 'branch_manager', 'admin']);
                })->first();
            }
        }

        if ($matchedUser) {
            // Guard: ensure matched account is not a staff/store_owner account
            $isBusinessAccount = $matchedUser->hasRole('store_owner') || $matchedUser->hasRole('staff')
                || $matchedUser->hasRole('branch_manager') || $matchedUser->hasRole('admin');

            if ($isBusinessAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email or phone belongs to a staff or owner account and cannot be added as a customer.',
                    'errors' => ['email' => ['This account belongs to a staff or owner user.']],
                ], 422);
            }

            $updates = [];
            if (! empty($validated['name']) && (empty($matchedUser->name) || Str::startsWith($matchedUser->email, 'walkin_'))) {
                $updates['name'] = $validated['name'];
            }
            if ($rawPhone && empty($matchedUser->phone)) {
                $updates['phone'] = $rawPhone;
            }
            if ($rawEmail && ! str_starts_with($rawEmail, 'walkin_') && Str::startsWith($matchedUser->email, 'walkin_')) {
                $updates['email'] = $rawEmail;
            }
            if (! empty($validated['suki_tag'])) {
                $updates['suki_tag'] = $validated['suki_tag'];
            }

            if (! empty($updates)) {
                $matchedUser->update($updates);
            }

            $user = $matchedUser;
        } else {
            $email = $rawEmail ?: ('walkin_'.time().'_'.Str::random(4).self::SUTURA_DOMAIN);
            $user = User::create([
                'email' => $email,
                'name' => $validated['name'],
                'phone' => $rawPhone,
                'suki_tag' => $validated['suki_tag'] ?? null,
                'password' => Hash::make(Str::random(16)),
            ]);
        }

        // Attach to store if not already attached, updating notes on pivot
        $store->customers()->syncWithoutDetaching([
            $user->id => ['notes' => $validated['notes'] ?? null],
        ]);

        $userJobs = $user->jobOrders()->where('store_id', $store->id)->get();
        $hasWalkInJobs = $userJobs->where('intake_channel', 'walk_in')->isNotEmpty();
        $hasOnlineJobs = $userJobs->where('intake_channel', 'online')->isNotEmpty();
        $isSyntheticWalkIn = Str::startsWith($user->email, 'walkin_');

        $user->is_walk_in = $isSyntheticWalkIn || ($hasWalkInJobs && ! $hasOnlineJobs);
        $user->intake_channel = $user->is_walk_in ? 'walk_in' : 'online';
        $user->active_jobs = $userJobs->whereNotIn('status', ['completed', 'cancelled', 'rejected'])->count();
        $user->completed_jobs = $userJobs->where('status', 'completed')->count();
        $user->total_spend = (float) $userJobs->sum(fn ($j) => (float) $j->total_amount - (float) $j->balance - (float) $j->discount_amount);

        return response()->json([
            'success' => true,
            'data' => $user,
            'message' => $matchedUser ? 'Existing customer matched and linked successfully.' : 'Customer created successfully.',
        ], $matchedUser ? 200 : 201);
    }

    public function update(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify customer belongs to store
        if (! $store->customers()->where('user_id', $customer->id)->exists() && ! $store->jobOrders()->where('customer_id', $customer->id)->exists()) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'required|string|max:20',
            'suki_tag' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:2000',
        ]);

        // An empty email on the edit form means "leave it as-is" — the form
        // already pre-fills this field with the customer's real email when
        // they have one (see CustomerFormModal), so a blank submission here
        // must never manufacture a fresh walkin_ placeholder and silently
        // overwrite a real, working login email. Only generate one if the
        // customer genuinely has no email on file at all.
        $email = $validated['email'] ?? null;
        if (! $email) {
            $email = $customer->email
                ?: ('walkin_'.time().'_'.Str::random(4).self::SUTURA_DOMAIN);
        }

        // Setting this record's email straight from input used to hit the
        // users.email unique constraint at the DB level with no warning if
        // it collided with a different user (business account or otherwise)
        // — a generic 500 instead of a message explaining why.
        if ($email !== $customer->email && User::where('email', $email)->where('id', '!=', $customer->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already in use by another account.',
                'errors' => ['email' => ['This email is already in use by another account.']],
            ], 422);
        }

        $customer->update([
            'name' => $validated['name'],
            'email' => $email,
            'phone' => $validated['phone'] ?? null,
            'suki_tag' => array_key_exists('suki_tag', $validated) ? $validated['suki_tag'] : $customer->suki_tag,
        ]);

        // Store-specific — lives on the pivot, not the User row (see
        // Store::customers()). Preserves the existing note when the request
        // doesn't include the field at all, same "omit = keep as-is" rule
        // already used above for suki_tag.
        if (array_key_exists('notes', $validated)) {
            $store->customers()->syncWithoutDetaching([
                $customer->id => ['notes' => $validated['notes']],
            ]);
        }

        $customer->store_notes = $store->customers()->where('user_id', $customer->id)->value('store_customers.notes');

        return response()->json([
            'success' => true,
            'data' => $customer,
            'message' => 'Customer updated successfully.',
        ]);
    }

    public function destroy(Request $request, Store $store, User $customer): JsonResponse
    {
        if (! $request->user()->hasRole('store_owner') && ! $request->user()->hasRole('branch_manager') && ! $request->user()->hasRole('staff')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify customer belongs to store — mirrors update()'s check exactly.
        // index() lists a customer if they appear EITHER in the store_customers
        // pivot OR in this store's job_orders, but this check only looked at
        // the pivot. A customer who arrived via public booking/self-registration/
        // job creation (Isabelle, Michael, Carlo in this store's real data) never
        // gets a pivot row at all, so clicking Delete on a customer clearly
        // visible in the list 404'd with "Customer not found in this store."
        if (! $store->customers()->where('user_id', $customer->id)->exists() && ! $store->jobOrders()->where('customer_id', $customer->id)->exists()) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        // Detach instead of deleting the user, since user might exist in other
        // stores. A customer with no pivot row (only reachable via job_orders)
        // has nothing to detach — that's expected, not an error: their real
        // order history keeps them in the list regardless, the same way a
        // completed job order can't be erased from view either.
        $store->customers()->detach($customer->id);

        return response()->json([
            'success' => true,
            'message' => 'Customer removed successfully.',
        ]);
    }
}
