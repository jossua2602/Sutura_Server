<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\AppointmentBookedNotification;
use App\Notifications\AppointmentStatusNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicBookingController extends Controller
{
    /**
     * Get booking settings for a store (branches, services, policy, questions).
     */
    public function getSettings(Store $store): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'name' => $store->name,
                'description' => $store->description,
                'business_type' => $store->business_type,
                'specializations' => $store->specializations ?? [],
                // The booking form's payment step showed a hardcoded
                // placeholder ("Printify Store") regardless of which store was
                // being booked — this store's own real payment details, set
                // via SettingsBasicInfo.tsx, were never exposed here at all.
                'gcash_number' => $store->gcash_number,
                'gcash_account_name' => $store->gcash_account_name,
                'gcash_qr_path' => $store->gcash_qr_path,
                'bank_name' => $store->bank_name,
                'bank_account_number' => $store->bank_account_number,
                'bank_account_name' => $store->bank_account_name,
                'bank_qr_path' => $store->bank_qr_path,
                // Drives whether the booking form even shows a payment step
                // at all — a plain consultation/fitting request shouldn't
                // ask for a deposit unless this store actually charges one to
                // reserve the slot (the Tailor-Gated Handshake rule: booking
                // is a request, not a paid commitment — that's the JobOrder
                // downpayment's job, once staff formalizes the request).
                'fitting_fee' => $store->fitting_fee,
                'booking_policy' => $store->booking_policy,
                'booking_questions' => $store->booking_questions ?? [],
                'max_appointments_per_day' => $store->max_appointments_per_day,
                'operating_hours' => $store->operating_hours,
                'active_special_hours' => $store->active_special_hours,
                'special_hours' => $store->specialHours()->get(),
                // is_main lets the booking page default to the main branch
                // when the customer didn't come from a specific one.
                'branches' => $store->branches()->live()->orderByDesc('is_main')->get(['id', 'slug', 'name', 'address', 'barangay', 'city', 'latitude', 'longitude', 'is_main']),
                // service_types/min_order_qty/custom_fields let the booking
                // wizard pick purposes and roster columns from the real
                // functional taxonomy instead of guessing from the name.
                'services' => $store->services()
                    ->where('is_active', true)
                    ->get(['id', 'name', 'base_price', 'estimated_days', 'service_types', 'min_order_qty', 'custom_fields']),
                'appointment_types' => Appointment::TYPES,
                'gcash_number' => $store->gcash_number,
                'gcash_account_name' => $store->gcash_account_name,
                'gcash_qr_path' => $store->gcash_qr_path,
                'bank_name' => $store->bank_name,
                'bank_account_number' => $store->bank_account_number,
                'bank_account_name' => $store->bank_account_name,
                'bank_qr_path' => $store->bank_qr_path,
            ],
        ]);
    }

    /**
     * Get public appointments for a store (anonymous time slots).
     */
    public function getAppointments(Store $store): JsonResponse
    {
        // Only return confirmed appointments (or pending if you want to block pending too)
        // Returning only scheduled_at and duration_minutes to keep customer details anonymous
        $appointments = $store->appointments()
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->where('scheduled_at', '>=', now()->subDay())
            ->get(['scheduled_at', 'duration_minutes', 'store_branch_id']);

        return response()->json([
            'success' => true,
            'data' => $appointments,
        ]);
    }

    /**
     * Submit a public appointment booking (unauthenticated customer).
     */
    public function submit(Request $request, Store $store): JsonResponse
    {
        $branchCount = $store->branches()->live()->count();
        if ($branchCount === 0) {
            return response()->json(['success' => false, 'message' => 'This shop has no verified branch to book at yet.'], 422);
        }

        $validated = $request->validate([
            // Customer info
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],

            // Booking details
            'appointment_type' => ['required', 'in:'.implode(',', Appointment::TYPES)],
            'purpose_label' => ['nullable', 'string', 'max:60', 'required_if:appointment_type,other'],
            'store_branch_id' => $branchCount > 1
                ? ['required', Rule::exists('store_branches', 'id')->where('store_id', $store->id)->where('status', 'active')->where('verification_status', 'verified')]
                : ['nullable', Rule::exists('store_branches', 'id')->where('store_id', $store->id)->where('status', 'active')->where('verification_status', 'verified')],
            'service_id' => ['nullable', Rule::exists('services', 'id')->where('store_id', $store->id)],
            // The catalog design this booking came from (the "Book an
            // Appointment" button on a design's page), scoped to this store.
            'catalog_item_id' => ['nullable', Rule::exists('catalog_items', 'id')->where('store_id', $store->id)],
            // The combo package this booking came from ("Book this package"), scoped to this store.
            'service_package_id' => ['nullable', Rule::exists('service_packages', 'id')->where('store_id', $store->id)],
            'selected_size' => ['nullable', 'string', 'max:50'],
            'selected_color' => ['nullable', 'string', 'max:100'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:'.now()->addMinutes(Appointment::MIN_BOOKING_LEAD_MINUTES)->toDateTimeString()],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link' => ['nullable', 'url', 'max:500'],
            'answers' => ['nullable', 'array'],
            'payment_method' => ['nullable', 'string', 'in:cash,gcash,paymaya'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'payment_receipt_path' => ['nullable', 'string', 'max:1000'],
        ], [
            'scheduled_at.after_or_equal' => 'Please choose a time at least 2 hours from now.',
        ]);

        $type = $validated['appointment_type'];

        // A package stands in for its services: when the type needs a service and
        // none was picked, the package's first service is used.
        if (! empty($validated['service_package_id']) && empty($validated['service_id'])) {
            $validated['service_id'] = \App\Models\ServicePackage::find($validated['service_package_id'])?->services()->value('services.id');
        }

        // ── Conditional service_id required ───────────────────────────────────
        if (
            in_array($type, Appointment::TYPES_REQUIRING_SERVICE)
            && empty($validated['service_id'])
        ) {
            return response()->json([
                'success' => false,
                'message' => "A service must be selected for appointment type: {$type}.",
                'errors' => ['service_id' => ["Service is required for {$type} appointments."]],
            ], 422);
        }

        // ── Conditional receipt required for non-cash payments ────────────────
        // The frontend's file-input `required` attribute is satisfied the
        // instant a file is *selected*, not once the async upload actually
        // finishes — this backstop makes sure a booking can't be submitted
        // with an empty receipt path if the upload was still in flight, failed,
        // or was simply skipped for a non-cash method.
        if (
            in_array($validated['payment_method'] ?? 'cash', ['gcash', 'paymaya'], true)
            && empty($validated['payment_receipt_path'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'A payment receipt is required for GCash/PayMaya bookings.',
                'errors' => ['payment_receipt_path' => ['Please upload your payment receipt before confirming.']],
            ], 422);
        }

        // ── Resolve branch ─────────────────────────────────────────────────────
        $branchId = $validated['store_branch_id'] ?? null;
        if ($branchCount === 1) {
            $branchId = $store->branches()->live()->first()->id;
        }

        // ── Double-booking check: checks both pending and confirmed appointments ───
        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $durationMinutes = $validated['duration_minutes'] ?? 60;

        // Same "we are not open" backstop AppointmentController enforces for
        // owner-created bookings/reschedules
        if ($closureTitle = $store->closureTitleOn($scheduledAt, $branchId)) {
            return response()->json([
                'success' => false,
                'message' => "The store is closed on this date ({$closureTitle}). Please choose a different day.",
            ], 409);
        }

        // For public storefront bookings, any slot held by a confirmed or pending
        // appointment is locked to eliminate online-vs-online collisions.
        if (Appointment::hasSchedulingConflict($store, $branchId, $scheduledAt, $durationMinutes, null, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already reserved or currently requested. Please choose a different time.',
            ], 409);
        }

        // ── Peak-season capacity blocker ────────────────────────────────────────
        // Once a day hits the store's declared max, stop taking new bookings for it
        // rather than letting quality slip from overcommitting production.
        if ($store->max_appointments_per_day) {
            $sameDayCount = $store->appointments()
                ->whereNotIn('status', ['cancelled'])
                ->whereDate('scheduled_at', $scheduledAt->toDateString())
                ->count();

            if ($sameDayCount >= $store->max_appointments_per_day) {
                return response()->json([
                    'success' => false,
                    'message' => 'This date is fully booked. Please choose another date.',
                ], 409);
            }
        }

        // ── Find or create customer ────────────────────────────────────────────
        $customer = User::where('email', $validated['email'])->first();

        if (! $customer) {
            $customer = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make(Str::random(16)),
            ]);

            $customerRole = Role::where('name', 'customer')->first();
            if ($customerRole) {
                $customer->roles()->attach($customerRole);
            }
        }

        // ── Anti-spam / rebooking-block guards ─────────────────────────────────
        // Only meaningful for a customer with prior history at this store — a
        // brand-new account (just created above) can't trip either check.
        if (Appointment::isBlockedFromRebooking($store, $customer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'This store is not accepting new bookings from this account. Please contact the store directly.',
            ], 403);
        }

        if (Appointment::hasActiveAppointment($store, $customer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active appointment at this shop. Cancel it first if you want to book a different date.',
            ], 409);
        }

        // A customer can legitimately have appointments at several different
        // stores/services, but not too close together in time — they can't
        // physically be in two shops at once, and can't teleport between
        // them either. Checked platform-wide (not just against $store) and
        // buffered by Appointment::CUSTOMER_TRAVEL_BUFFER_MINUTES on both
        // sides, not just a bare time-overlap check — see
        // hasCustomerScheduleConflict()'s own docblock.
        if (Appointment::hasCustomerScheduleConflict($customer->id, $scheduledAt, $durationMinutes)) {
            return response()->json([
                'success' => false,
                'message' => 'This time is too close to another appointment you already have. Please leave at least '.Appointment::CUSTOMER_TRAVEL_BUFFER_MINUTES.' minutes between appointments so you have time to travel.',
            ], 409);
        }

        // ── Associate customer with this store ─────────────────────────────────
        // Ensures public-booked customers appear in the store's customer
        // list / CRM (CustomerController::index reads store_customers).
        // Using attach() with skipIfAttached avoids duplicating the pivot
        // row if this customer already booked or was added manually.
        if (! $store->customers()->where('user_id', $customer->id)->exists()) {
            $store->customers()->attach($customer->id);
        }

        // ── Create appointment (with transactional concurrency guard) ──────────
        $appointment = DB::transaction(function () use ($store, $customer, $branchId, $type, $validated, $scheduledAt, $durationMinutes) {
            if (Appointment::hasSchedulingConflict($store, $branchId, $scheduledAt, $durationMinutes, null, true)) {
                return null;
            }

            return $store->appointments()->create([
                'customer_id' => $customer->id,
                'store_branch_id' => $branchId,
                'service_id' => $validated['service_id'] ?? null,
                'catalog_item_id' => $validated['catalog_item_id'] ?? null,
                'service_package_id' => $validated['service_package_id'] ?? null,
                'selected_size' => $validated['selected_size'] ?? null,
                'selected_color' => $validated['selected_color'] ?? null,
                'appointment_type' => $type,
                'purpose_label' => $type === 'other' ? trim((string) ($validated['purpose_label'] ?? '')) : null,
                'intake_channel' => 'online',
                'scheduled_at' => $validated['scheduled_at'],
                'duration_minutes' => $durationMinutes,
                'notes' => $validated['notes'] ?? null,
                'reference_images' => $validated['reference_images'] ?? null,
                'reference_link' => $validated['reference_link'] ?? null,
                'answers' => $validated['answers'] ?? null,
                'status' => 'pending',
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'payment_reference' => $validated['payment_reference'] ?? null,
                'payment_receipt_path' => $validated['payment_receipt_path'] ?? null,
                'payment_status' => 'pending',
            ]);
        });

        if (! $appointment) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already reserved or currently requested. Please choose a different time.',
            ], 409);
        }

        // Notify store owner
        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new AppointmentBookedNotification($appointment));
        }

        // Also notify the customer themselves — a record of what they just
        // booked (when + why), in-app and by email, so if they forget the
        // details later they have something to check back against instead
        // of only the store owner knowing.
        $customer->notify(new AppointmentStatusNotification($appointment, 'submitted'));

        return response()->json([
            'success' => true,
            'message' => 'Appointment booked successfully. The store will confirm your booking shortly.',
            'data' => $appointment,
        ], 201);
    }
}
