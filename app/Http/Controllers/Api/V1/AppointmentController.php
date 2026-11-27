<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreAppointmentRequest;
use App\Http\Requests\Store\StoreFollowUpAppointmentRequest;
use App\Http\Requests\Store\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\Store;
use App\Models\StaffProfile;
use App\Notifications\AppointmentAssignedNotification;
use App\Notifications\AppointmentBookedNotification;
use App\Notifications\AppointmentPaymentStatusNotification;
use App\Notifications\AppointmentStatusNotification;
use App\Notifications\StoreActivityNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    /**
     * Branch managers/staff pinned to a branch may only act on appointments
     * that belong to that same branch (store_owner is unrestricted). Mirrors
     * JobOrderController::branchAccessDenied() exactly, for the same reason:
     * index() already filters the list by branch for these roles, so nothing
     * should be reachable here that wouldn't have shown up there.
     */
    private function branchAccessDenied(Request $request, Appointment $appointment): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('store_owner')) {
            return null;
        }

        $userBranchId = $user->staffProfile->store_branch_id ?? null;

        if ($userBranchId && $appointment->store_branch_id && (int) $userBranchId !== (int) $appointment->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'This appointment belongs to a different branch.'], 403);
        }

        return null;
    }

    /**
     * A store_special_hours row with is_closed=true covering the requested
     * date is an explicit, unambiguous "we are not open" announcement —
     * unlike regular weekly operating_hours (where an early/late exception
     * might be legitimate), there's no reason to let a booking land on a
     * day the store deliberately announced as closed. Previously this data
     * only ever reached the frontend for display (customer booking page,
     * owner's special-hours card) — nothing on the backend ever checked it,
     * so a request bypassing that UI, or a walk-in the owner books
     * themselves without noticing their own closure notice, went through
     * anyway.
     */
    private function fallsOnAnnouncedClosure(Store $store, Carbon $scheduledAt, ?int $branchId = null): ?string
    {
        return $store->closureTitleOn($scheduledAt, $branchId);
    }

    /**
     * Notifies the store owner's own in-app bell whenever staff or a branch
     * manager makes a change on their behalf — mirrors
     * JobOrderController::notifyOwnerOfActivity() exactly, for the same
     * reason: the owner performing the change themselves already knows what
     * they just did, so this only fires for other actors.
     */
    /**
     * Tells the staff member an appointment is assigned to about a change to
     * it (new time, cancelled) — they planned their day around it. Never the
     * person who made the change.
     */
    private function notifyAssignedStaff(Request $request, Appointment $appointment, string $type, string $title, string $message): void
    {
        $staffId = $appointment->assigned_staff_id;
        if (! $staffId || (int) $staffId === (int) $request->user()->id) {
            return;
        }
        \App\Models\User::find($staffId)?->notify(new StoreActivityNotification(
            $type, $title, $message, '/dashboard/appointments', ['appointment_id' => $appointment->id]
        ));
    }

    private function notifyOwnerOfActivity(Request $request, Store $store, array $payload): void
    {
        if ($request->user()->hasRole('store_owner')) {
            return;
        }

        $owner = $store->owner;
        if (! $owner) {
            return;
        }

        $owner->notify(new StoreActivityNotification(
            $payload['type'],
            $payload['title'],
            $payload['message'],
            $payload['url'],
            $payload['extra'] ?? []
        ));
    }

    /**
     * Cross-store "My Appointments" for whoever is logged in — the customer
     * counterpart to index() above, which is store-scoped and staff/owner
     * only. Mirrors JobOrderTrackingController::myOrders()'s same pattern
     * and safe field subset (no internal notes, no staff assignment). No
     * role gate: filters by the caller's own id as customer_id.
     */
    public function myAppointments(Request $request): JsonResponse
    {
        $appointments = Appointment::where('customer_id', $request->user()->id)
            ->with(['store:id,name,slug,logo_path', 'branch:id,name,address,city', 'service:id,name,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price'])
            ->orderByDesc('scheduled_at')
            ->paginate($request->input('per_page', 20));

        $appointments->getCollection()->transform(fn (Appointment $appointment) => [
            'id' => $appointment->id,
            'appointment_type' => $appointment->appointment_type,
            'purpose_label' => $appointment->purpose_label,
            'intake_channel' => $appointment->intake_channel,
            'status' => $appointment->status,
            'scheduled_at' => $appointment->scheduled_at,
            'checked_in_at' => $appointment->checked_in_at,
            'duration_minutes' => $appointment->duration_minutes,
            'service_name' => $appointment->service?->name,
            'service' => $appointment->service ? $appointment->service->only(['id', 'name', 'service_category', 'service_leaf_type']) : null,
            // The catalog design this was booked from, with the size/color picked on its page.
            'catalog_item' => $appointment->catalogItem?->only(['id', 'name', 'department', 'subcategory', 'garment_structure', 'garment_type']),
            'service_package' => $appointment->servicePackage?->only(['id', 'name', 'bundle_price', 'service_category']),
            'selected_size' => $appointment->selected_size,
            'selected_color' => $appointment->selected_color,
            'payment_status' => $appointment->payment_status,
            'cancellation_reason' => $appointment->cancellation_reason,
            'rejection_reason' => $appointment->rejection_reason_code ? (Appointment::REJECTION_REASONS[$appointment->rejection_reason_code] ?? 'Other') : null,
            'rejection_note' => $appointment->rejection_note,
            'rebooking_blocked' => (bool) $appointment->rebooking_blocked,
            // A walk-in took this request's slot: the customer must pick a new time.
            'needs_new_time' => $appointment->status === 'pending' && $appointment->outcome === 'rescheduled',
            'store' => $appointment->store ? [
                'name' => $appointment->store->name,
                'slug' => $appointment->store->slug,
                'logo_path' => $appointment->store->logo_path,
            ] : null,
            'branch' => $appointment->branch ? [
                'name' => $appointment->branch->name,
                'address' => $appointment->branch->address,
                'city' => $appointment->branch->city,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $appointments->items(),
            'meta' => [
                'current_page' => $appointments->currentPage(),
                'last_page' => $appointments->lastPage(),
                'total' => $appointments->total(),
            ],
        ]);
    }

    /**
     * Customer cancels their own appointment — distinct from destroy() below
     * (owner/manager only). No reason required (that's only meaningful when
     * the *store* cancels on a customer), and never touches
     * rebooking_blocked — cancelling your own booking never blocks you from
     * booking again.
     */
    public function cancelMine(Request $request, Appointment $appointment): JsonResponse
    {
        if ($appointment->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => "This appointment isn't yours."], 403);
        }

        if (! $appointment->canTransitionTo('cancelled')) {
            return response()->json([
                'success' => false,
                'message' => "A {$appointment->status} appointment cannot be cancelled.",
            ], 422);
        }

        $appointment->update(['status' => 'cancelled']);

        $store = $appointment->store;
        if ($store) {
            $this->notifyAssignedStaff($request, $appointment, 'appointment_cancelled', 'Appointment Cancelled', "{$request->user()->name} cancelled their appointment.");
            $this->notifyOwnerOfActivity($request, $store, [
                'type' => 'appointment_cancelled',
                'title' => 'Appointment Cancelled',
                'message' => "{$request->user()->name} cancelled their own appointment.",
                'url' => '/dashboard/appointments',
                'extra' => ['appointment_id' => $appointment->id],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment cancelled.',
            'data' => $appointment,
        ]);
    }

    /**
     * The customer moves their own PENDING request to another time — needed when
     * a walk-in took the slot (preemptByWalkIn keeps the request pending and
     * asks them to pick another time, but customers had no way to). Same
     * guards as a fresh booking: closures, the slot's other holders, the
     * store's daily cap, and the customer's own travel buffer. Confirmed
     * appointments stay with the store (only they can move those).
     */
    public function rescheduleMine(Request $request, Appointment $appointment): JsonResponse
    {
        if ($appointment->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Appointment not found.'], 404);
        }

        if ($appointment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only a request that is still waiting for the store can be moved. Ask the store, or cancel and book again.',
            ], 422);
        }

        $validated = $request->validate(
            ['scheduled_at' => ['required', 'date', 'after_or_equal:'.now()->addMinutes(Appointment::MIN_BOOKING_LEAD_MINUTES)->toDateTimeString()]],
            ['scheduled_at.after_or_equal' => 'Please choose a time at least 2 hours from now.'],
        );

        $store = $appointment->store;
        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $duration = $appointment->duration_minutes ?? 60;

        if ($closure = $store->closureTitleOn($scheduledAt, $appointment->store_branch_id)) {
            return response()->json(['success' => false, 'message' => "The store is closed on this date ({$closure}). Please choose a different day."], 409);
        }

        if (Appointment::hasSchedulingConflict($store, $appointment->store_branch_id, $scheduledAt, $duration, $appointment->id, true)) {
            return response()->json(['success' => false, 'message' => 'This time slot is already reserved or currently requested. Please choose a different time.'], 409);
        }

        if ($store->max_appointments_per_day) {
            $sameDay = $store->appointments()
                ->whereNotIn('status', ['cancelled'])
                ->where('id', '!=', $appointment->id)
                ->whereDate('scheduled_at', $scheduledAt->toDateString())
                ->count();
            if ($sameDay >= $store->max_appointments_per_day) {
                return response()->json(['success' => false, 'message' => 'This date is fully booked. Please choose another date.'], 409);
            }
        }

        if (Appointment::hasCustomerScheduleConflict($request->user()->id, $scheduledAt, $duration, $appointment->id)) {
            return response()->json([
                'success' => false,
                'message' => 'That time is too close to another appointment you already have. Leave at least '.Appointment::CUSTOMER_TRAVEL_BUFFER_MINUTES.' minutes between appointments.',
            ], 409);
        }

        $old = $appointment->scheduled_at?->format('M j, Y g:i A');
        $tag = "[Moved by customer from {$old}]";

        $appointment->update([
            'scheduled_at' => $scheduledAt,
            'notes' => $appointment->notes ? $tag."\n".$appointment->notes : $tag,
            'outcome' => null, // clears the "needs a new time" flag
        ]);
        if ($appointment->reminder_sent_at) {
            $appointment->forceFill(['reminder_sent_at' => null])->save();
        }

        $this->notifyAssignedStaff($request, $appointment, 'appointment_rescheduled', 'Appointment Time Changed', "{$request->user()->name} moved their appointment to ".$scheduledAt->format('M j, Y g:i A').'.');
        $this->notifyOwnerOfActivity($request, $store, [
            'type' => 'appointment_rescheduled',
            'title' => 'Customer Picked a New Time',
            'message' => "{$request->user()->name} moved their request to ".$scheduledAt->format('M j, Y g:i A').'.',
            'url' => '/dashboard/appointments',
            'extra' => ['appointment_id' => $appointment->id],
        ]);

        return response()->json(['success' => true, 'message' => 'Your request was moved to the new time.', 'data' => $appointment]);
    }

    /**
     * Single-appointment detail for the customer's own "view full detail"
     * screen — same customer-scoped ownership check and safe field subset
     * as myAppointments(), just one row with a bit more (duration, payment
     * status, full branch address, and the customer's own notes/reference
     * images from booking — their own submitted data, safe to show back).
     */
    public function myAppointmentDetail(Request $request, Appointment $appointment): JsonResponse
    {
        if ($appointment->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Appointment not found.'], 404);
        }

        $appointment->load(['store:id,name,slug,logo_path', 'branch:id,name,address,city', 'service:id,name,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $appointment->id,
                'appointment_type' => $appointment->appointment_type,
            'purpose_label' => $appointment->purpose_label,
                'intake_channel' => $appointment->intake_channel,
                'status' => $appointment->status,
                'scheduled_at' => $appointment->scheduled_at,
                'checked_in_at' => $appointment->checked_in_at,
                'duration_minutes' => $appointment->duration_minutes,
                'service_name' => $appointment->service?->name,
                'service' => $appointment->service ? $appointment->service->only(['id', 'name', 'service_category', 'service_leaf_type']) : null,
                // The catalog design this was booked from, with the size/color picked on its page.
                'catalog_item' => $appointment->catalogItem?->only(['id', 'name', 'department', 'subcategory', 'garment_structure', 'garment_type']),
                // The combo package this was booked from, with what it includes.
                'service_package' => $appointment->servicePackage,
                'selected_size' => $appointment->selected_size,
                'selected_color' => $appointment->selected_color,
                'payment_status' => $appointment->payment_status,
                'payment_method' => $appointment->payment_method,
                'notes' => $appointment->notes,
                'reference_link' => $appointment->reference_link,
                'shared_link' => $appointment->shared_link,
                'shared_images' => $appointment->shared_images ?? [],
                'needs_new_time' => $appointment->status === 'pending' && $appointment->outcome === 'rescheduled',
                'store_branch_id' => $appointment->store_branch_id,
                'store_id' => $appointment->store_id,
                'cancellation_reason' => $appointment->cancellation_reason,
            'rejection_reason' => $appointment->rejection_reason_code ? (Appointment::REJECTION_REASONS[$appointment->rejection_reason_code] ?? 'Other') : null,
            'rejection_note' => $appointment->rejection_note,
                'rebooking_blocked' => (bool) $appointment->rebooking_blocked,
                'store' => $appointment->store ? [
                    'name' => $appointment->store->name,
                    'slug' => $appointment->store->slug,
                    'logo_path' => $appointment->store->logo_path,
                ] : null,
                'branch' => $appointment->branch ? [
                    'name' => $appointment->branch->name,
                    'address' => $appointment->branch->address,
                    'city' => $appointment->branch->city,
                ] : null,
            ],
        ]);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();
        $roles = $user->roles->pluck('name');

        $query = $store->appointments()->with([
            'customer:id,name,email,contact_email,phone',
            'service:id,name,base_price,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price',
            'branch:id,name',
            'assignedStaff:id,name',
            'jobOrder:id,order_number',
        ]);

        $branchId = null;
        if ($roles->contains('branch_manager') || ($roles->contains('staff') && ! $roles->contains('store_owner'))) {
            $branchId = $user->staffProfile->store_branch_id ?? null;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
        }

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('store_branch_id', $branchId)
                    ->orWhereNull('store_branch_id');
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->input('assigned_to') === 'me') {
            $query->where('assigned_staff_id', $request->user()->id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('appointment_type', $request->type);
        }
        if ($request->filled('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('scheduled_at', 'asc')->get(),
        ]);
    }

    /**
     * Shared by store() and createFollowUp() — both are in-person/staff-
     * initiated bookings, so both need the exact same "we are not open"
     * closure check and walk-in-priority conflict check. Extracted so the
     * two creation paths can never quietly drift apart on this safety logic.
     */
    private function assertSlotAvailable(Store $store, Carbon $scheduledAt, ?int $branchId, int $durationMinutes): ?JsonResponse
    {
        if ($closureTitle = $this->fallsOnAnnouncedClosure($store, $scheduledAt, $branchId)) {
            return response()->json([
                'success' => false,
                'message' => "The store is closed on this date ({$closureTitle}). Please choose a different day.",
            ], 409);
        }

        // Walk-in priority: Walk-ins are checked against confirmed/in_progress appointments only.
        // A physical walk-in customer is the source of truth ("kung sino ang makauna").
        if (Appointment::hasSchedulingConflict($store, $branchId, $scheduledAt, $durationMinutes, null, false)) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already booked by another confirmed appointment. Please choose a different time.',
            ], 409);
        }

        return null;
    }

    // ─── Store (Owner/Manager creates appointment on behalf of customer) ───────

    public function store(StoreAppointmentRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();

        // Auto-assign branch when store has only one
        if ($store->branches()->count() === 1) {
            $data['store_branch_id'] = $store->branches()->first()->id;
        }

        // Staff (and branch managers) pinned to a branch book only for that branch,
        // whatever branch the form sent — same scoping as the appointment list.
        $user = $request->user();
        if (! $user->hasRole('store_owner') && ($pinned = $user->staffProfile?->store_branch_id)) {
            $data['store_branch_id'] = $pinned;
        }

        // Auto-calculated duration based on Appointment Type — the owner's
        // form no longer offers a manual Duration selector at all.
        $data['duration_minutes'] = $data['duration_minutes']
            ?? Appointment::TYPE_DEFAULT_DURATIONS[$data['appointment_type']]
            ?? 60;
        // In-store entries created by owner/manager/staff are walk-ins and auto-confirmed
        // so the slot is reserved and locked immediately (online bookings arrive as 'pending').
        $data['status'] = 'confirmed';
        $data['intake_channel'] = 'walk_in';

        $scheduledAt = Carbon::parse($data['scheduled_at']);

        if ($denied = $this->assertSlotAvailable($store, $scheduledAt, $data['store_branch_id'] ?? null, $data['duration_minutes'])) {
            return $denied;
        }

        // Query any overlapping pending online bookings before creating the walk-in
        $overlappingPending = Appointment::getOverlappingPendingAppointments(
            $store,
            $data['store_branch_id'] ?? null,
            $scheduledAt,
            $data['duration_minutes']
        );

        $appointment = $store->appointments()->create($data);
        $appointment->load(['customer:id,name,email,contact_email,phone', 'service:id,name,base_price,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

        // Walk-in claims the slot: preempt all overlapping pending online bookings
        $preemptedCount = 0;
        foreach ($overlappingPending as $pendingAppt) {
            $pendingAppt->preemptByWalkIn($appointment, $request->user());
            $preemptedCount++;
        }

        // (The free-fitting limit / extra-fitting fee was removed from the shop
        // settings, so booking an extra fitting no longer adds a fee.)

        // Notify store owner of new booking
        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new AppointmentBookedNotification($appointment));
        }

        return response()->json([
            'success' => true,
            'message' => $preemptedCount > 0
                ? "Walk-in appointment confirmed. {$preemptedCount} pending online booking(s) for this slot were notified to reschedule."
                : 'Appointment created successfully.',
            'preempted_count' => $preemptedCount,
            'data' => $appointment->load(['customer:id,name,email,contact_email,phone', 'service:id,name,base_price,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']),
        ], 201);
    }

    // ─── Follow-Up (Staff or Owner/Manager creates an operational return visit) ─

    /**
     * "Balik po kayo Friday 2PM" — formalized. Deliberately narrower than
     * store() above: no payment fields, no answers/priority, no service
     * picker. Available to Staff too (unlike store(), which stays
     * Owner/Branch-Manager only) — this is the one appointment-creation
     * authority CUSTOMER-WORKFLOW.md §7.4/§20 and STAFF-WORKFLOW.md §17
     * actually grant Staff. Customer never creates this themselves either
     * way. Reuses store()'s exact closure/conflict-check safety logic via
     * assertSlotAvailable() — never duplicated, never relaxed.
     */
    public function createFollowUp(StoreFollowUpAppointmentRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();

        $jobOrder = null;
        if (! empty($data['job_order_id'])) {
            $jobOrder = JobOrder::where('store_id', $store->id)->find($data['job_order_id']);
        }

        $customerId = $data['customer_id'] ?? $jobOrder?->customer_id;
        if (! $customerId) {
            return response()->json([
                'success' => false,
                'message' => 'A customer or an existing job order is required to create a follow-up appointment.',
            ], 422);
        }

        if ($store->branches()->count() === 1) {
            $data['store_branch_id'] = $store->branches()->first()->id;
        }

        $data['customer_id'] = $customerId;
        $data['duration_minutes'] = $data['duration_minutes']
            ?? Appointment::TYPE_DEFAULT_DURATIONS[$data['appointment_type']]
            ?? 60;
        // Same reasoning as store(): an in-person/staff-initiated booking is
        // a confirmed walk-in slot, not a pending online request.
        $data['status'] = 'confirmed';
        $data['intake_channel'] = 'walk_in';

        $scheduledAt = Carbon::parse($data['scheduled_at']);

        if ($denied = $this->assertSlotAvailable($store, $scheduledAt, $data['store_branch_id'] ?? null, $data['duration_minutes'])) {
            return $denied;
        }

        $overlappingPending = Appointment::getOverlappingPendingAppointments(
            $store,
            $data['store_branch_id'] ?? null,
            $scheduledAt,
            $data['duration_minutes']
        );

        $appointment = $store->appointments()->create($data);

        $preemptedCount = 0;
        foreach ($overlappingPending as $pendingAppt) {
            $pendingAppt->preemptByWalkIn($appointment, $request->user());
            $preemptedCount++;
        }

        // The customer is very likely not present when Staff schedules this
        // (unlike store()'s counter walk-in, where the owner notifies
        // *themselves*'s notification bell about a booking that just
        // happened in front of them) — this is the one creation path where
        // the customer genuinely needs telling. Reuses the same
        // AppointmentStatusNotification class update()/destroy() already use.
        $appointment->customer?->notify(new AppointmentStatusNotification(
            $appointment,
            'confirmed',
            null,
            $request->user()
        ));
        $this->notifyOwnerOfActivity($request, $store, [
            'type' => 'appointment_follow_up_created',
            'title' => 'Follow-Up Appointment Scheduled',
            'message' => "A follow-up {$appointment->appointment_type} was scheduled for {$appointment->customer?->name}.",
            'url' => '/dashboard/appointments',
            'extra' => ['appointment_id' => $appointment->id],
        ]);

        return response()->json([
            'success' => true,
            'message' => $preemptedCount > 0
                ? "Follow-up appointment confirmed. {$preemptedCount} pending online booking(s) for this slot were notified to reschedule."
                : 'Follow-up appointment created.',
            'preempted_count' => $preemptedCount,
            'data' => $appointment->load(['customer:id,name,email,contact_email,phone', 'branch:id,name', 'jobOrder:id,order_number']),
        ], 201);
    }

    // ─── Update (status transitions + reschedule) ─────────────────────────────

    public function update(UpdateAppointmentRequest $request, Store $store, Appointment $appointment): JsonResponse
    {
        $error = null;
        $status = 200;

        if ($appointment->store_id !== $store->id) {
            $error = "This appointment isn't part of your shop.";
            $status = 403;
        } elseif ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        } elseif ($appointment->isTerminal()) {
            $error = "A {$appointment->status} appointment cannot be modified.";
            $status = 422;
        } else {
            $user = $request->user();
            $roles = $user->roles->pluck('name');
            $isStaff = $roles->contains('staff') && ! $roles->contains('store_owner') && ! $roles->contains('branch_manager');

            $data = $request->validated();
            $newStatus = $data['status'] ?? null;

            // ── Role enforcement & State transition check ────────────────────────
            $error = $this->validateAndEnforceRole($appointment, $isStaff, $newStatus);
            if ($error) {
                $status = ($error === 'Staff are not authorized to perform this status change.') ? 403 : 422;
            }

            if ($isStaff) {
                // Staff cannot modify schedule or notes
                unset($data['scheduled_at'], $data['notes'], $data['assigned_staff_id']);
            }

            // ── Reschedule logic ──────────────────────────────────────────────────
            $isRescheduled = false;
            if (! $error && ! empty($data['scheduled_at'])) {
                $oldAt = $appointment->scheduled_at?->format('Y-m-d H:i:s');
                $newAt = date('Y-m-d H:i:s', strtotime($data['scheduled_at']));

                if ($oldAt !== $newAt) {
                    // Only pending or confirmed appointments can be rescheduled
                    if (! in_array($appointment->status, ['pending', 'confirmed'])) {
                        $error = 'Only pending or confirmed appointments can be rescheduled.';
                        $status = 422;
                    } elseif ($closureTitle = $this->fallsOnAnnouncedClosure($store, Carbon::parse($data['scheduled_at']), $appointment->store_branch_id)) {
                        $error = "The store is closed on this date ({$closureTitle}). Please choose a different day.";
                        $status = 409;
                    } elseif (Appointment::hasSchedulingConflict(
                        $store,
                        $appointment->store_branch_id,
                        Carbon::parse($data['scheduled_at']),
                        $data['duration_minutes'] ?? $appointment->duration_minutes ?? 60,
                        $appointment->id
                    )) {
                        $error = 'This time slot is already booked. Please choose a different time.';
                        $status = 409;
                    } else {
                        $isRescheduled = true;

                        // Audit log
                        $store->auditLogs()->create([
                            'user_id' => $user->id,
                            'action' => 'appointment_rescheduled',
                            'model_type' => Appointment::class,
                            'model_id' => $appointment->id,
                            'payload' => [
                                'old_scheduled_at' => $oldAt,
                                'new_scheduled_at' => $newAt,
                                'reason' => $data['notes'] ?? 'Rescheduled by owner/staff',
                            ],
                            'ip_address' => $request->ip(),
                        ]);

                        // Visible reschedule note so UI/UX and owner always have concrete proof
                        $rescheduleTag = '[Rescheduled from '.date('M j, Y g:i A', strtotime($oldAt)).']';
                        if (empty($data['notes'])) {
                            $data['notes'] = $rescheduleTag.($appointment->notes ? "\n".$appointment->notes : '');
                        } elseif (! str_contains($data['notes'], $rescheduleTag)) {
                            $data['notes'] = $rescheduleTag."\n".$data['notes'];
                        }
                    }
                }
            }

            // ── Arrival tracking (auto-derive, never trust the client) ───────────
            // Whenever staff writes checked_in_at (the existing Check-In
            // action), classify it against scheduled_at server-side —
            // arrival_status is never itself submitted by the client. A
            // 5-minute grace window either side of scheduled_at counts as
            // on_time so normal clock drift/rounding doesn't read as early
            // or late.
            // The browser sends UTC ("…Z"); store it as Manila time like every other
            // column here, or 3:07 AM is saved as 7:07 PM the day before.
            if (! empty($data['checked_in_at'])) {
                $data['checked_in_at'] = Carbon::parse($data['checked_in_at'])->setTimezone(config('app.timezone'));
            }
            if (! $error && ! empty($data['checked_in_at']) && $appointment->scheduled_at) {
                $checkedInAt = $data['checked_in_at'];
                $graceMinutes = 5;
                if ($checkedInAt->lt($appointment->scheduled_at->copy()->subMinutes($graceMinutes))) {
                    $data['arrival_status'] = 'early';
                } elseif ($checkedInAt->gt($appointment->scheduled_at->copy()->addMinutes($graceMinutes))) {
                    $data['arrival_status'] = 'late';
                } else {
                    $data['arrival_status'] = 'on_time';
                }
            }

            // ── Early-Arrival Accommodation ───────────────────────────────────────
            // Staff may only accommodate an early arrival if the branch
            // actually has capacity right now — an early customer can never
            // automatically displace another confirmed appointment (rule 11).
            // Capacity is checked the same way a normal booking would be:
            // is the branch free from now for as long as this appointment
            // takes? (Not "until the original time": a customer checked in
            // days ahead would then clash with every booking in between.)
            // If it isn't free, staff keep the customer waiting instead.
            if (! $error && ($data['early_arrival_decision'] ?? null) === 'accommodated') {
                if (($appointment->arrival_status ?? null) !== 'early') {
                    $error = 'This appointment is not marked as an early arrival.';
                    $status = 422;
                } else {
                    $now = Carbon::now();
                    $serviceMinutes = (int) ($appointment->duration_minutes ?: 60);

                    if (Appointment::hasSchedulingConflict($store, $appointment->store_branch_id, $now, $serviceMinutes, $appointment->id, false)) {
                        $error = "This branch has another appointment in the next {$serviceMinutes} minutes, so this customer can't be taken early. Ask them to wait for their time.";
                        $status = 409;
                    } else {
                        $data['actual_service_start_at'] = $now;
                        $data['accommodated_by_id'] = $user->id;

                        $store->auditLogs()->create([
                            'user_id' => $user->id,
                            'action' => 'appointment_early_arrival_accommodated',
                            'model_type' => Appointment::class,
                            'model_id' => $appointment->id,
                            'payload' => [
                                'scheduled_at' => $appointment->scheduled_at->format('Y-m-d H:i:s'),
                                'checked_in_at' => $appointment->checked_in_at?->format('Y-m-d H:i:s'),
                                'actual_service_start_at' => $now->format('Y-m-d H:i:s'),
                            ],
                            'ip_address' => $request->ip(),
                        ]);
                    }
                }
            }

            // ── Confirm-time conflict re-check ────────────────────────────────────
            // hasSchedulingConflict() only blocks against already-CONFIRMED
            // appointments, so two customers can each hold a *pending* booking
            // for the same slot with no warning at creation time — the moment
            // either one gets confirmed, it has to be re-checked here, or the
            // owner can silently confirm both and end up double-booked with
            // nothing catching it.
            if (! $error && $newStatus === 'confirmed' && $appointment->status !== 'confirmed') {
                $checkAt = $isRescheduled ? Carbon::parse($data['scheduled_at']) : $appointment->scheduled_at;
                $checkDuration = $data['duration_minutes'] ?? $appointment->duration_minutes ?? 60;

                if (Appointment::hasSchedulingConflict($store, $appointment->store_branch_id, $checkAt, $checkDuration, $appointment->id, false)) {
                    $error = 'This time slot is already booked by another confirmed appointment. Please reschedule before confirming.';
                    $status = 409;
                }
            }

            if (! $error) {
                $sharedChanged = (array_key_exists('shared_link', $data) && ($data['shared_link'] ?? null) !== $appointment->shared_link)
                    || (array_key_exists('shared_images', $data) && ($data['shared_images'] ?? []) !== ($appointment->shared_images ?? []));

                // Read before saving: after update() getOriginal() already returns the new status,
                // which made "did the status change?" always false (no confirm notice, email or text).
                $statusBefore = $appointment->status;

                // Perform update
                $appointment->update($data);

                // If confirmed, preempt any other overlapping pending requests on this slot
                if ($newStatus === 'confirmed') {
                    $otherPending = Appointment::getOverlappingPendingAppointments(
                        $store,
                        $appointment->store_branch_id,
                        $appointment->scheduled_at,
                        $appointment->duration_minutes ?? 60,
                        $appointment->id
                    );
                    foreach ($otherPending as $other) {
                        $other->preemptByWalkIn($appointment, $user);
                    }
                }

                // reminder_sent_at (server-derived, not fillable — see
                // RemindUpcomingAppointments) tracks whether the ~24h-ahead
                // reminder already fired for this appointment's *time slot*.
                // A reschedule doesn't change that flag on its own, so a
                // customer reminded once, then rescheduled, would silently
                // never get reminded again for the new time — the reminder
                // command's whereNull('reminder_sent_at') check would just
                // keep skipping it. Reset it so the new time gets its own
                // reminder pass.
                if ($isRescheduled && $appointment->reminder_sent_at) {
                    $appointment->forceFill(['reminder_sent_at' => null])->save();
                }

                $appointment->load(['customer:id,name,email,contact_email,phone', 'service:id,name,base_price,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

                // ── Notifications ─────────────────────────────────────────────────────
                $customer = $appointment->customer;
                if ($customer) {
                    if ($sharedChanged && ($appointment->shared_link || ! empty($appointment->shared_images))) {
                        $customer->notify(new AppointmentStatusNotification(
                            $appointment,
                            'shared',
                            ($appointment->store?->name ?? 'The store').' shared a link or photos for your appointment. Open it to see them.',
                            $request->user()
                        ));
                    }
                    if ($isRescheduled) {
                        $this->notifyAssignedStaff($request, $appointment, 'appointment_rescheduled', 'Appointment Time Changed', "Appointment for {$customer->name} is now ".$appointment->scheduled_at?->format('M j, Y g:i A').'.');
                        $customer->notify(new AppointmentStatusNotification($appointment, 'rescheduled', null, $request->user()));
                        $this->notifyOwnerOfActivity($request, $store, [
                            'type' => 'appointment_rescheduled',
                            'title' => 'Appointment Rescheduled',
                            'message' => "Appointment for {$customer->name} was rescheduled.",
                            'url' => '/dashboard/appointments',
                            'extra' => ['appointment_id' => $appointment->id],
                        ]);
                    } elseif ($newStatus && $newStatus !== $statusBefore) {
                        $customer->notify(new AppointmentStatusNotification($appointment, $newStatus, null, $request->user()));
                        $this->notifyOwnerOfActivity($request, $store, [
                            'type' => 'appointment_'.$newStatus,
                            'title' => 'Appointment Updated',
                            'message' => "Appointment for {$customer->name} is now {$newStatus}.",
                            'url' => '/dashboard/appointments',
                            'extra' => ['appointment_id' => $appointment->id],
                        ]);
                    }
                }
            }
        }

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], $status);
        }

        return response()->json([
            'success' => true,
            'data' => $appointment,
        ]);
    }

    // ─── Complete (dedicated action with type-specific logic) ─────────────────

    public function complete(Request $request, Store $store, Appointment $appointment): JsonResponse
    {
        $error = null;
        $status = 200;

        if ($appointment->store_id !== $store->id) {
            $error = "This appointment isn't part of your shop.";
            $status = 403;
        } elseif ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        } elseif ($appointment->status !== 'in_progress') {
            $error = 'Only in-progress appointments can be marked as completed.';
            $status = 422;
        } else {
            $request->validate([
                'notes' => ['nullable', 'string', 'max:2000'],
                'job_order_id' => ['nullable', Rule::exists('job_orders', 'id')->where('store_id', $store->id)],
                'measurement_id' => ['nullable', Rule::exists('measurements', 'id')->where('store_id', $store->id)],
                'outcome' => ['nullable', 'string', 'in:completed,rescheduled,no_show,converted_to_job,cancelled'],
                'fitting_notes' => ['nullable', 'string', 'max:2000'],
            ]);

            $type = $appointment->appointment_type;
            $jobOrderId = $request->job_order_id ? (int) $request->job_order_id : null;

            // Type-specific rules
            $error = $this->validateCompleteTypeRules($type, $jobOrderId);
            if ($error) {
                $status = 422;
            } else {
                // Update appointment
                $updateData = ['status' => 'completed'];
                if ($request->filled('notes')) {
                    $updateData['notes'] = $appointment->notes
                        ? $appointment->notes."\n\n[Completion Note] ".$request->notes
                        : $request->notes;
                }
                if ($request->filled('outcome')) {
                    $updateData['outcome'] = $request->outcome;
                }
                if ($request->filled('fitting_notes')) {
                    $updateData['fitting_notes'] = $request->fitting_notes;
                }

                $appointment->update($updateData);

                // fitting_notes was previously captured (if at all) only on
                // the appointment itself — invisible to whoever actually does
                // Final Adjustments on the linked job, since they work from
                // the Job Detail page, not the appointment record. Attaching
                // it directly to the job's own notes is what actually gets it
                // in front of the tailor doing the work.
                if ($request->filled('fitting_notes') && $jobOrderId) {
                    $jobOrder = JobOrder::find($jobOrderId);
                    if ($jobOrder) {
                        $jobOrder->update([
                            'notes' => trim(($jobOrder->notes ? $jobOrder->notes."\n\n" : '')
                                .'[Fitting Notes — '.now()->format('M j, Y').'] '.$request->fitting_notes),
                        ]);
                    }
                }
                $appointment->load(['customer:id,name,email,contact_email,phone', 'service:id,name,base_price,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

                // Notify customer
                $customer = $appointment->customer;
                if ($customer) {
                    $customer->notify(new AppointmentStatusNotification($appointment, 'completed', null, $request->user()));
                    $this->notifyOwnerOfActivity($request, $store, [
                        'type' => 'appointment_completed',
                        'title' => 'Appointment Completed',
                        'message' => "Appointment for {$customer->name} was marked completed.",
                        'url' => '/dashboard/appointments',
                        'extra' => ['appointment_id' => $appointment->id],
                    ]);
                }

                // Audit log
                $store->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'appointment_completed',
                    'model_type' => Appointment::class,
                    'model_id' => $appointment->id,
                    'payload' => [
                        'type' => $type,
                        'job_order_id' => $request->job_order_id,
                        'measurement_id' => $request->measurement_id,
                    ],
                    'ip_address' => $request->ip(),
                ]);
            }
        }

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], $status);
        }

        return response()->json([
            'success' => true,
            'data' => $appointment,
        ]);
    }

    private function validateAndEnforceRole(Appointment $appointment, bool $isStaff, ?string $newStatus): ?string
    {
        // A decline always carries a reason — it only goes through the reject action.
        if ($newStatus === 'rejected') {
            return 'Use the Reject action — a reason is required.';
        }

        if ($isStaff) {
            $staffAllowed = ['in_progress', 'completed'];
            if ($newStatus && ! in_array($newStatus, $staffAllowed)) {
                return 'Staff are not authorized to perform this status change.';
            }
        }

        if ($newStatus && $newStatus !== $appointment->status && ! $appointment->canTransitionTo($newStatus)) {
            return "Invalid status transition: '{$appointment->status}' → '{$newStatus}'.";
        }

        return null;
    }

    private function validateCompleteTypeRules(string $type, ?int $jobOrderId): ?string
    {
        if ($type === 'fitting' && empty($jobOrderId)) {
            return 'A fitting appointment must be linked to an existing job order when completing.';
        }
        if ($type === 'pickup' && empty($jobOrderId)) {
            return 'A pickup appointment must reference the completed job order.';
        }

        return null;
    }

    // ─── Destroy (cancel — owner/manager only) ────────────────────────────────

    public function destroy(Request $request, Store $store, Appointment $appointment): JsonResponse
    {
        $error = null;
        $status = 200;

        if ($appointment->store_id !== $store->id) {
            $error = "This appointment isn't part of your shop.";
            $status = 403;
        } elseif ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        } elseif ($appointment->isTerminal()) {
            $error = "A {$appointment->status} appointment cannot be cancelled.";
            $status = 422;
        } elseif (! $appointment->canTransitionTo('cancelled')) {
            $error = "Cannot cancel an appointment with status '{$appointment->status}'.";
            $status = 422;
        } else {
            // The store is cancelling on the customer — unlike cancelMine()
            // above, the customer deserves to know why, so a reason is
            // required here. block_rebooking defaults to false (the
            // customer may still book again); the store opts in to blocking.
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:2000'],
                'block_rebooking' => ['nullable', 'boolean'],
            ]);

            $appointment->update([
                'status' => 'cancelled',
                'cancellation_reason' => $validated['reason'],
                'rebooking_blocked' => $validated['block_rebooking'] ?? false,
            ]);
            $appointment->load(['customer:id,name,email,contact_email,phone', 'service:id,name,base_price,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price', 'branch:id,name', 'assignedStaff:id,name', 'jobOrder:id,order_number']);

            $customer = $appointment->customer;
            if ($customer) {
                $customer->notify(new AppointmentStatusNotification($appointment, 'cancelled', null, $request->user()));
                $this->notifyAssignedStaff($request, $appointment, 'appointment_cancelled', 'Appointment Cancelled', "Appointment for {$customer->name} was cancelled.");
                $this->notifyOwnerOfActivity($request, $store, [
                    'type' => 'appointment_cancelled',
                    'title' => 'Appointment Cancelled',
                    'message' => "Appointment for {$customer->name} was cancelled.",
                    'url' => '/dashboard/appointments',
                    'extra' => ['appointment_id' => $appointment->id],
                ]);
            }
        }

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Appointment cancelled.',
            'data' => $appointment,
        ]);
    }

    // ─── Reject a pending request (owner/manager only) ────────────────────────

    public function reject(Request $request, Store $store, Appointment $appointment): JsonResponse
    {
        if ($appointment->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => "This appointment isn't part of your shop."], 403);
        }
        if ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        }
        if (! $appointment->canTransitionTo('rejected')) {
            return response()->json(['success' => false, 'message' => 'Only a pending request can be rejected.'], 422);
        }

        $validated = $request->validate([
            'reason_code' => ['required', Rule::in(array_keys(Appointment::REJECTION_REASONS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $appointment->update([
            'status' => 'rejected',
            'rejection_reason_code' => $validated['reason_code'],
            'rejection_note' => $validated['note'] ?? null,
        ]);

        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'appointment_rejected',
            'model_type' => Appointment::class,
            'model_id' => $appointment->id,
            'payload' => ['reason' => Appointment::REJECTION_REASONS[$validated['reason_code']], 'note' => $validated['note'] ?? null],
            'ip_address' => $request->ip(),
        ]);

        $appointment->load(['store:id,name,slug,logo_path', 'customer:id,name,email,contact_email,phone']);
        $appointment->customer?->notify(new AppointmentStatusNotification($appointment, 'rejected', null, $request->user()));

        return response()->json(['success' => true, 'message' => 'Appointment rejected.', 'data' => $appointment]);
    }

    // ─── Assign (or unassign) the staff who handles an appointment ────────────

    public function assign(Request $request, Store $store, Appointment $appointment): JsonResponse
    {
        if ($appointment->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => "This appointment isn't part of your shop."], 403);
        }
        if ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        }
        if ($appointment->isTerminal()) {
            return response()->json(['success' => false, 'message' => "A {$appointment->status} appointment can't be assigned."], 422);
        }

        $validated = $request->validate(['staff_id' => ['nullable', 'integer']]);
        $staffId = $validated['staff_id'] ?? null;

        if ($staffId !== null) {
            $profile = StaffProfile::where('user_id', $staffId)->where('store_id', $store->id)->first();
            if (! $profile) {
                return response()->json(['success' => false, 'message' => "That staff member isn't part of this shop."], 422);
            }
            // A branch appointment is handled by someone from that branch (owner may still pick anyone from the store).
            if ($appointment->store_branch_id && $profile->store_branch_id
                && (int) $profile->store_branch_id !== (int) $appointment->store_branch_id
                && ! $request->user()->hasRole('store_owner')) {
                return response()->json(['success' => false, 'message' => 'Choose staff from this appointment\'s branch.'], 422);
            }
        }

        $changed = (int) ($appointment->assigned_staff_id ?? 0) !== (int) ($staffId ?? 0);
        $appointment->update(['assigned_staff_id' => $staffId]);

        if ($changed) {
            $store->auditLogs()->create([
                'user_id' => $request->user()->id,
                'action' => 'appointment_staff_assigned',
                'model_type' => Appointment::class,
                'model_id' => $appointment->id,
                'payload' => ['staff_id' => $staffId],
                'ip_address' => $request->ip(),
            ]);
            if ($staffId !== null) {
                \App\Models\User::find($staffId)?->notify(new AppointmentAssignedNotification($appointment));
            }
        }

        return response()->json([
            'success' => true,
            'message' => $staffId ? 'Staff assigned.' : 'Staff unassigned.',
            'data' => $appointment->load(['customer:id,name,email,contact_email,phone', 'assignedStaff:id,name']),
        ]);
    }

    public function verifyPayment(Request $request, Store $store, Appointment $appointment): JsonResponse
    {
        if ($appointment->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => "This appointment isn't part of your shop."], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $appointment)) {
            return $denied;
        }

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,rejected',
        ]);

        $oldPaymentStatus = $appointment->payment_status;

        $appointment->update([
            'payment_status' => $validated['payment_status'],
        ]);

        // Previously silent either way — the customer had no signal their
        // deposit/receipt was even reviewed, paid or rejected.
        if (in_array($validated['payment_status'], ['paid', 'rejected'], true) && $validated['payment_status'] !== $oldPaymentStatus) {
            $appointment->customer?->notify(new AppointmentPaymentStatusNotification($appointment, $validated['payment_status']));
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data' => $appointment->load(['customer:id,name,email,contact_email,phone', 'service:id,name,base_price,service_category,service_leaf_type', 'catalogItem:id,name,department,subcategory,garment_structure,garment_type', 'servicePackage:id,name,bundle_price,service_category,image_url', 'servicePackage.services:id,name,base_price', 'branch:id,name']),
        ]);
    }
}
