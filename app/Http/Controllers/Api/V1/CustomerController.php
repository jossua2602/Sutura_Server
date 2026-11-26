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
            return response()->json(['message' => "Only shop accounts can do this."], 403);
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

        $customerIds = $this->withoutRemoved($store, collect($customerIds));

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
            return response()->json(['message' => "Only shop accounts can do this."], 403);
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
            ->with('customer:id,name,email,contact_email')
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
            return response()->json(['message' => "Only shop accounts can do this."], 403);
        }

        \App\Support\JobMeasurementSync::syncForCustomer($store, $customer->id);

        $measurements = $store->measurements()
            ->where('customer_id', $customer->id)
            ->with('customer:id,name,email,contact_email')
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
            ->with('customer:id,name,email,contact_email')
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
            return response()->json(['message' => "Only shop accounts can do this."], 403);
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
        if ($rawPhone && ! preg_match('/^09\d{9}$/', $digitsPhone)) {
            return $this->invalidPhoneResponse();
        }

        // The email and number staff type for a walk-in are only where order
        // updates go — not a login. So:
        //  - Re-use a record only when it is clearly the same person already in
        //    THIS shop's customer list: same contact number, same name, and no
        //    conflicting email. Never link to another shop's customer, or to
        //    whichever account happens to own the email (a family member sharing
        //    a Gmail used to get the walk-in's orders in their own account).
        //  - Otherwise create a new walk-in: a private placeholder login, with the
        //    typed email saved as the notification email (contact_email).
        $matchedUser = null;
        if ($digitsPhone) {
            $shopCustomerIds = collect()
                ->merge($store->customers()->pluck('users.id'))
                ->merge($store->jobOrders()->pluck('customer_id'))
                ->merge($store->appointments()->pluck('customer_id'))
                ->filter()
                ->unique();

            $matchedUser = User::whereIn('id', $shopCustomerIds)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($validated['name']))])
                ->where(function ($q) use ($rawPhone, $digitsPhone) {
                    $q->where('phone', $rawPhone)
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ?", [$digitsPhone]);
                })
                ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['store_owner', 'staff', 'branch_manager', 'admin']))
                ->get()
                // A different email on file means a different person sharing the number.
                ->first(fn (User $u) => ! $rawEmail || ! $u->notificationEmail() || strcasecmp($u->notificationEmail(), $rawEmail) === 0);
        }

        if ($matchedUser) {
            $updates = [];
            if ($rawEmail && ! $matchedUser->notificationEmail()) {
                $updates['contact_email'] = $rawEmail;
            }
            if (! empty($validated['suki_tag'])) {
                $updates['suki_tag'] = $validated['suki_tag'];
            }
            if (! empty($updates)) {
                $matchedUser->update($updates);
            }

            $user = $matchedUser;
        } else {
            $user = User::create([
                'email' => 'walkin_'.time().'_'.Str::random(6).self::SUTURA_DOMAIN,
                'contact_email' => $rawEmail,
                'name' => $validated['name'],
                'phone' => $digitsPhone,
                'suki_tag' => $validated['suki_tag'] ?? null,
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        // Attach to store if not already attached, updating notes on pivot
        // Adding a removed customer again brings them back (removed_at cleared).
        $store->customers()->syncWithoutDetaching([
            $user->id => ['notes' => $validated['notes'] ?? null, 'removed_at' => null],
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
            return response()->json(['message' => "Only shop accounts can do this."], 403);
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

        // Same rule as store(): an 11-digit PH mobile, "+63 917…" saved as "0917…".
        $digits = preg_replace('/\D/', '', $validated['phone']);
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }
        if (! preg_match('/^09\d{9}$/', $digits)) {
            return $this->invalidPhoneResponse();
        }
        $validated['phone'] = $digits;

        // A walk-in (placeholder login) has no login to change: the email field
        // is only where their order updates go, so it edits contact_email and
        // may be shared with another person.
        if (Str::startsWith((string) $customer->email, 'walkin_')) {
            $customer->update([
                'name' => $validated['name'],
                'contact_email' => ! empty($validated['email']) ? trim($validated['email']) : null,
                'phone' => $validated['phone'] ?? null,
                'suki_tag' => array_key_exists('suki_tag', $validated) ? $validated['suki_tag'] : $customer->suki_tag,
            ]);
            if (array_key_exists('notes', $validated)) {
                $store->customers()->syncWithoutDetaching([$customer->id => ['notes' => $validated['notes']]]);
            }
            $customer->store_notes = $store->customers()->where('user_id', $customer->id)->value('store_customers.notes');

            return response()->json(['success' => true, 'data' => $customer, 'message' => 'Customer updated successfully.']);
        }

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
            return response()->json(['message' => "Only shop accounts can do this."], 403);
        }

        // Verify customer belongs to store — mirrors update()'s check exactly.
        // index() lists a customer if they appear EITHER in the store_customers
        // pivot OR in this store's job_orders, but this check only looked at
        // the pivot. A customer who arrived via public booking/self-registration/
        // job creation (Isabelle, Michael, Carlo in this store's real data) never
        // gets a pivot row at all, so clicking Delete on a customer clearly
        // visible in the list 404'd with "Customer not found in this store."
        if (! $store->customers()->where('user_id', $customer->id)->exists()
            && ! $store->jobOrders()->where('customer_id', $customer->id)->exists()
            && ! $store->appointments()->where('customer_id', $customer->id)->exists()) {
            return response()->json(['message' => self::MSG_NOT_FOUND], 404);
        }

        // Hide, don't delete: the user may belong to other shops, and their
        // appointments/orders here stay as they were. Detaching alone wasn't
        // enough — index() also lists anyone with an appointment or order, so a
        // removed customer with history came straight back. removed_at hides
        // them until they book or order again (see withoutRemoved()).
        $store->customers()->syncWithoutDetaching([$customer->id => ['removed_at' => now()]]);

        return response()->json([
            'success' => true,
            'message' => 'Customer removed successfully.',
        ]);
    }

    /**
     * Drops customers the shop removed (store_customers.removed_at), unless
     * they came back afterwards: an appointment or order made after the
     * removal lists them again. Their history itself is never touched.
     */
    private function withoutRemoved(Store $store, \Illuminate\Support\Collection $ids): \Illuminate\Support\Collection
    {
        $removed = $store->customers()->wherePivotNotNull('removed_at')->pluck('store_customers.removed_at', 'users.id');
        if ($removed->isEmpty()) {
            return $ids;
        }

        $latest = fn ($query) => $query->whereIn('customer_id', $removed->keys())
            ->selectRaw('customer_id, MAX(created_at) as last_at')->groupBy('customer_id')->pluck('last_at', 'customer_id');
        $lastAppt = $latest($store->appointments()->getQuery());
        $lastJob = $latest($store->jobOrders()->getQuery());

        return $ids->reject(function ($id) use ($removed, $lastAppt, $lastJob) {
            if (! isset($removed[$id])) {
                return false;
            }
            $cameBack = max((string) ($lastAppt[$id] ?? ''), (string) ($lastJob[$id] ?? ''));

            return $cameBack === '' || $cameBack <= (string) $removed[$id];
        })->values();
    }

    private function invalidPhoneResponse(): JsonResponse
    {
        $message = 'Phone number must be 11 digits starting with 09, e.g. 09171234567.';

        return response()->json(['success' => false, 'message' => $message, 'errors' => ['phone' => [$message]]], 422);
    }
}
