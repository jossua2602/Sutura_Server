<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreJobOrderRequest;
use App\Http\Requests\Store\UpdateJobOrderRequest;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\JobOrder;
use App\Models\Measurement;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServicePricing;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Support\OrderNumber;
use App\Models\User;
use App\Notifications\AppointmentStatusNotification;
use App\Notifications\CustomerPaymentRejectedNotification;
use App\Notifications\CustomMessageNotification;
use App\Notifications\JobOrderAssignedNotification;
use App\Notifications\JobStatusUpdatedNotification;
use App\Notifications\NewJobOrderNotification;
use App\Notifications\OrderReadyNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\PaymentRejectedNotification;
use App\Notifications\StoreActivityNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobOrderController extends Controller
{
    /**
     * Branch managers/staff pinned to a branch may only act on job orders
     * that belong to that same branch (store_owner is unrestricted). Jobs or
     * users with no branch assigned (single-branch stores) are never blocked.
     */
    private function branchAccessDenied(Request $request, JobOrder $jobOrder): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('store_owner')) {
            return null;
        }

        $userBranchId = $user->staffProfile->store_branch_id ?? null;

        if ($userBranchId && $jobOrder->store_branch_id && (int) $userBranchId !== (int) $jobOrder->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'This job order belongs to a different branch.'], 403);
        }

        return null;
    }

    /**
     * Notifies the store owner's own in-app bell — and, when the activity
     * happened on a specific branch, that branch's own manager too, not just
     * the owner — whenever staff or a branch manager makes a change on their
     * behalf. The owner performing the change themselves already knows what
     * they just did, so this only fires for other actors; the acting branch
     * manager is likewise never notified of their own action.
     */
    private function notifyOwnerOfActivity(Request $request, Store $store, array $payload, ?JobOrder $jobOrder = null): void
    {
        $actor = $request->user();

        if (! $actor->hasRole('store_owner')) {
            $owner = $store->owner;
            if ($owner) {
                $owner->notify(new StoreActivityNotification(
                    $payload['type'],
                    $payload['title'],
                    $payload['message'],
                    $payload['url'],
                    $payload['extra'] ?? []
                ));
            }
        }

        if ($jobOrder?->store_branch_id) {
            $branchManagers = User::whereHas('staffProfile', function ($q) use ($store, $jobOrder) {
                $q->where('store_id', $store->id)
                    ->where('store_branch_id', $jobOrder->store_branch_id)
                    ->where('is_branch_manager', true);
            })->where('id', '!=', $actor->id)->get();

            foreach ($branchManagers as $manager) {
                $manager->notify(new StoreActivityNotification(
                    $payload['type'],
                    $payload['title'],
                    $payload['message'],
                    $payload['url'],
                    $payload['extra'] ?? []
                ));
            }
        }
    }

    /**
     * "New Job Order Created" goes to the managers of the branch the order
     * belongs to (including one who created it — it's their branch's feed) and
     * never to other branches' managers. The owner gets it too, tagged with the
     * branch: their bell shows main-shop orders by default and a branch's
     * orders when they switch to that branch (NotificationController::feed).
     * Non-manager staff get their own "New Order" ping from JobOrder::booted().
     */
    private function notifyNewJobOrder(Store $store, JobOrder $jobOrder): void
    {
        $recipients = collect([$store->owner]);

        if ($jobOrder->store_branch_id) {
            $recipients = $recipients->merge(User::whereHas('staffProfile', function ($q) use ($store, $jobOrder) {
                $q->where('store_id', $store->id)
                    ->where('store_branch_id', $jobOrder->store_branch_id)
                    ->where('is_branch_manager', true);
            })->get());
        }

        foreach ($recipients->filter()->unique('id') as $user) {
            $user->notify(new NewJobOrderNotification($jobOrder));
            Cache::driver('file')->forget("user_notifications_{$user->id}");
        }
    }

    /**
     * "ORD-0001", "ORD-0002", … — the store's single order series, shared with
     * walk-in catalog orders (see App\Support\OrderNumber).
     */
    private function generateOrderNumber(Store $store): string
    {
        return OrderNumber::next($store->id);
    }

    /**
     * Globally unique (not scoped per store, unlike order_number) — the
     * public tracking lookup has no other way to know which store's table to
     * search. Lets a customer check their order status without an account,
     * the real "sa na po ba?" pain point named in interview research.
     */
    public static function generateStorePrefix($store): string
    {
        if (is_numeric($store)) {
            $store = Store::find($store);
        }
        if (! $store) {
            return 'SUTR';
        }
        if (! empty($store->store_code)) {
            return strtoupper($store->store_code);
        }
        $known = [
            'thread-needle' => 'TNED',
            'bautista-tailors' => 'BAUT',
            'villanueva-atelier' => 'VILL',
        ];
        if (isset($known[$store->slug])) {
            return $known[$store->slug];
        }

        $letters = preg_replace('/[^A-Za-z]/', '', $store->name);
        if (strlen($letters) >= 4) {
            return strtoupper(substr($letters, 0, 4));
        }

        return strtoupper(str_pad($letters, 4, 'X'));
    }

    private function generateTrackingCode(?Store $store = null, ?string $orderNumber = null): string
    {
        $prefix = self::generateStorePrefix($store);
        $charset = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        while (true) {
            $suffix = '';
            // 6 random characters (~1 billion combinations) after the shop prefix: the code is the
            // only thing standing between the public tracker and someone else's order.
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $charset[random_int(0, strlen($charset) - 1)];
            }
            // Ensure combination of letters and numbers
            if (! preg_match('/[0-9]/', $suffix) || ! preg_match('/[A-Z]/', $suffix)) {
                continue;
            }
            $code = "{$prefix}{$suffix}";
            if (! JobOrder::withTrashed()->where('tracking_code', $code)->exists()) {
                return $code;
            }
        }
    }

    /**
     * Customer-facing "Request a Repair" — the first-ever customer-initiated
     * job order creation path (every other job order is still created by
     * the store owner/staff dashboard). Deliberately narrow: only fires for
     * a Service actually tagged alteration_repair, and pricing comes only
     * from that service's own real ServicePricing rows (never a guessed
     * flat amount) — a repair with no matching pricing line can't be priced
     * honestly here, so it's rejected rather than defaulting to 0.
     */
    public function customerRepairRequest(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|integer',
            'garment_description' => 'required|string|max:500',
            'pre_existing_damage_notes' => 'required|string|max:2000',
            'pricing_ids' => 'required|array|min:1',
            'pricing_ids.*' => 'integer',
            'reference_images' => 'nullable|array|max:10',
            'reference_images.*' => 'string|max:1000',
        ]);

        $service = Service::where('id', $validated['service_id'])
            ->where('store_id', $store->id)
            ->first();

        if (! $service || ! $service->hasType(Service::TYPE_ALTERATION_REPAIR)) {
            return response()->json(['success' => false, 'message' => 'This store has no matching repair/alteration service.'], 404);
        }

        $pricingRows = ServicePricing::where('service_id', $service->id)
            ->whereIn('id', $validated['pricing_ids'])
            ->get();

        if ($pricingRows->count() !== count($validated['pricing_ids'])) {
            return response()->json(['success' => false, 'message' => 'One or more selected repair items are no longer available.'], 422);
        }

        $totalAmount = (float) $pricingRows->sum('amount');

        $mainBranch = $store->branches()->where('is_main', true)->first();

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'garment_category' => 'alteration_repair',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'reference_images' => $validated['reference_images'] ?? [],
            'custom_order_data' => [
                // The customer's original request, written once at submission and
                // never overwritten — kept out of `notes` specifically because
                // `notes` is where staff/owner operational commentary gets appended
                // elsewhere in this codebase (e.g. AppointmentController::complete()'s
                // fitting-notes append). Mixing the two would let staff text drift
                // the customer's own repair request out of view. See
                // docs/REPAIR-WORKFLOW.md §3.
                'repair_note' => $validated['garment_description'],
                'pre_existing_damage_notes' => $validated['pre_existing_damage_notes'],
                'repair_items' => $pricingRows->map(fn ($p) => ['label' => $p->label, 'amount' => (float) $p->amount])->values()->all(),
            ],
        ]);

        $jobOrder->load(['customer:id,name', 'service']);

        $store->customers()->syncWithoutDetaching([$jobOrder->customer_id]);

        $this->notifyNewJobOrder($store, $jobOrder);

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Customer-facing "Bulk Order" off a Catalog Designs item — Size +
     * Quantity only for now (Color deferred). Requires the item to have a
     * linked, bulk_sublimation-typed Service (CatalogItem.service_id) —
     * there's no fallback/auto-detected service, so an unlinked item simply
     * has no Bulk Order entry point. Pricing is the item's own real price
     * × total quantity (never guessed), and each unit becomes its own
     * team_roster row (name left blank — a browsing customer has no real
     * per-recipient names, unlike a staff-entered team roster) so the store's
     * existing Team Roster & Size Sheet UI can track/complete each piece
     * individually, exactly as it already does for staff-created bulk jobs.
     */
    public function customerBulkOrder(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'catalog_item_id' => 'required|integer',
            'organization_name' => 'nullable|string|max:255',
            'store_branch_id' => 'nullable|integer',
            'roster' => 'required|array|min:1|max:500',
            'roster.*.name' => 'nullable|string|max:100',
            'roster.*.size' => 'required|string|max:50',
        ]);

        $item = CatalogItem::where('id', $validated['catalog_item_id'])
            ->where('store_id', $store->id)
            ->first();

        if (! $item || ! $item->service_id) {
            return response()->json(['success' => false, 'message' => 'This item is not available for bulk ordering.'], 404);
        }

        $service = Service::find($item->service_id);
        if (! $service || ! $service->hasType(Service::TYPE_BULK_SUBLIMATION)) {
            return response()->json(['success' => false, 'message' => 'This item is not available for bulk ordering.'], 404);
        }

        $totalQty = count($validated['roster']);

        if ($service->min_order_qty > 1 && $totalQty < $service->min_order_qty) {
            return response()->json([
                'success' => false,
                'message' => "This service requires a minimum of {$service->min_order_qty} pieces.",
            ], 422);
        }

        // Keep whatever extra columns the customer added on the roster
        // table (e.g. "Jersey Number") — $request->validate() above only
        // proves name/size are present, it doesn't strip the rest, so the
        // raw rows (not $validated) carry those extra keys through into
        // custom_order_data.team_roster for the store owner to see.
        $roster = collect($request->input('roster'))->map(function ($row) {
            return array_merge($row, ['name' => $row['name'] ?? null]);
        })->all();

        $sizeCounts = collect($validated['roster'])->countBy('size');
        $totalAmount = (float) $item->price * $totalQty;

        // Customer-picked branch (via the catalog page's Find sheet) wins
        // when it actually belongs to this store; otherwise fall back to the
        // store's main branch same as before the picker existed.
        $pickedBranch = ! empty($validated['store_branch_id'])
            ? $store->branches()->where('id', $validated['store_branch_id'])->first()
            : null;
        $mainBranch = $pickedBranch ?? $store->branches()->where('is_main', true)->first();

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'catalog_item_id' => $item->id,
            'garment_category' => $item->garment_type,
            'material_source' => 'store_supplied',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'notes' => "Bulk order: {$item->name} — ".$sizeCounts->map(fn ($qty, $size) => "{$qty}x {$size}")->implode(', '),
            'reference_images' => $item->images()->where('is_primary', true)->value('image_url') ? [$item->images()->where('is_primary', true)->value('image_url')] : [],
            'custom_order_data' => [
                'team_name' => $validated['organization_name'] ?? $item->name,
                'team_roster' => $roster,
            ],
        ]);

        $jobOrder->load(['customer:id,name', 'service', 'catalogItem:id,name']);

        $store->customers()->syncWithoutDetaching([$jobOrder->customer_id]);

        $this->notifyNewJobOrder($store, $jobOrder);

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Customer-facing "Made to Order" — a single piece of a specific
     * Showroom item, no team roster (that's Bulk Order's shape, not this
     * one). Requires the item to have a linked Service same as Bulk Order,
     * but explicitly rejects a bulk_sublimation-typed one — that service's
     * min_order_qty is a real business rule (e.g. "10 pieces minimum") a
     * single-piece order would otherwise silently bypass.
     */
    public function customerMadeToOrder(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'catalog_item_id' => 'required|integer',
            'size' => 'nullable|string|max:50',
        ]);

        $item = CatalogItem::where('id', $validated['catalog_item_id'])
            ->where('store_id', $store->id)
            ->first();

        if (! $item || ! $item->service_id) {
            return response()->json(['success' => false, 'message' => 'This item is not available to order directly yet.'], 404);
        }

        $service = Service::find($item->service_id);
        if (! $service) {
            return response()->json(['success' => false, 'message' => 'This item is not available to order directly yet.'], 404);
        }
        if ($service->hasType(Service::TYPE_BULK_SUBLIMATION)) {
            return response()->json(['success' => false, 'message' => 'This item requires a Bulk Order — please use the bulk order flow instead.'], 422);
        }

        if (! empty($validated['size']) && ! empty($item->sizes) && ! in_array($validated['size'], $item->sizes, true)) {
            return response()->json(['success' => false, 'message' => 'That size is not offered for this item.'], 422);
        }

        $totalAmount = (float) $item->price;
        $mainBranch = $store->branches()->where('is_main', true)->first();
        $primaryImage = $item->images()->where('is_primary', true)->value('image_url');

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $mainBranch?->id,
            'customer_id' => $request->user()->id,
            'service_id' => $service->id,
            'catalog_item_id' => $item->id,
            'garment_category' => $item->garment_type,
            'material_source' => 'store_supplied',
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'notes' => 'Made to Order: '.$item->name.(! empty($validated['size']) ? ' (Reference size: '.$validated['size'].')' : ''),
            'reference_images' => $primaryImage ? [$primaryImage] : [],
            'custom_order_data' => ! empty($validated['size']) ? ['reference_size' => $validated['size']] : null,
        ]);

        $jobOrder->load(['customer:id,name', 'service', 'catalogItem:id,name']);

        $store->customers()->syncWithoutDetaching([$jobOrder->customer_id]);

        $this->notifyNewJobOrder($store, $jobOrder);

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    /**
     * Customer-facing "Online Order" straight off a Service's detail page —
     * no Catalog Design or appointment needed. Lands as an online, pending
     * job order the shop approves or rejects from its Orders board, same as
     * Made-to-Order. Price is the picked pricing line (or the service's own
     * current price) × quantity; the shop can still adjust it after review.
     * Repair-only services keep their own flow (damage notes, repair items).
     */
    public function customerServiceOrder(Request $request, Store $store): JsonResponse
    {
        // Same four parts as the shop's walk-in wizard (customer, measurements,
        // order specs, invoice & payment), stored under the same
        // custom_order_data keys so the shop reads both kinds of order alike.
        $validated = $request->validate([
            'service_id' => 'required|integer',
            'contact_phone' => 'required|string|max:20',
            'store_branch_id' => 'nullable|integer',

            'order_mode' => 'required|in:individual,bulk',
            'measurement_id' => 'nullable|integer',
            'individual_measurements' => 'nullable|array',
            'individual_measurements.unit' => 'nullable|in:in,cm',
            'individual_measurements.*' => 'nullable',
            'size_breakdown' => 'required_if:order_mode,bulk|array',
            'size_breakdown.*' => 'nullable|integer|min:0|max:500',
            'team_name' => 'nullable|string|max:255',
            'measurement_sheets' => 'nullable|array|max:5',
            'measurement_sheets.*' => 'string|max:1000',

            'pricing_id' => 'nullable|integer',
            'size' => 'nullable|string|max:50',
            'material_source' => ['required', Rule::in(JobOrder::MATERIAL_SOURCES)],
            'material_description' => 'nullable|string|max:500',
            'answers' => 'nullable|array',
            'answers.*' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
            'reference_images' => 'nullable|array|max:5',
            'reference_images.*' => 'string|max:1000',
            'reference_link' => ['nullable', 'string', 'max:500', new \App\Rules\SafeLink],

            'quantity' => 'required_if:order_mode,individual|nullable|integer|min:1|max:500',
            'rush_requested' => 'nullable|boolean',
            'needed_by' => 'nullable|date|after:today',
            'payment_term' => 'required|in:full,downpayment',
            // Paid up front by GCash only: the shop's GCash account + the customer's receipt.
            'payment_method_id' => 'nullable|integer',
            'payment_receipt' => 'nullable|string|max:2048',
            'payment_reference' => 'nullable|string|max:255',
        ]);

        // Only the walk-in form's measurement fields, each a plain number.
        $measurements = null;
        if ($validated['order_mode'] === 'individual' && ! empty($validated['individual_measurements'])) {
            $measurements = ['unit' => $validated['individual_measurements']['unit'] ?? 'in'];
            foreach (['chest', 'waist', 'hips', 'shoulders', 'length', 'neck', 'sleeve', 'inseam'] as $key) {
                $value = $validated['individual_measurements'][$key] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (! is_numeric($value) || $value <= 0 || $value > 200) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Measurements must be numbers between 0 and 200.',
                        'errors' => ["individual_measurements.$key" => ['Enter a number between 0 and 200.']],
                    ], 422);
                }
                $measurements[$key] = (string) (0 + $value);
            }
            if (count($measurements) === 1) {
                $measurements = null;
            }
        }

        $sizeBreakdown = null;
        if ($validated['order_mode'] === 'bulk') {
            $sizeBreakdown = collect($validated['size_breakdown'] ?? [])
                ->only(['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL'])
                ->map(fn ($qty) => (int) $qty)
                ->filter(fn ($qty) => $qty > 0)
                ->all();
            if (! $sizeBreakdown) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add how many pieces you need in at least one size.',
                    'errors' => ['size_breakdown' => ['Enter at least one size quantity.']],
                ], 422);
            }
        }

        $service = Service::where('id', $validated['service_id'])
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->with('pricing')
            ->first();

        if (! $service) {
            return response()->json(['success' => false, 'message' => 'This service is not available to order right now.'], 404);
        }
        if ($service->service_types === [Service::TYPE_ALTERATION_REPAIR]) {
            return response()->json(['success' => false, 'message' => 'Repairs use the repair request form instead.'], 422);
        }

        $quantity = $sizeBreakdown ? (int) array_sum($sizeBreakdown) : (int) $validated['quantity'];
        if ($quantity > 500) {
            return response()->json(['success' => false, 'message' => 'Online orders are capped at 500 pieces. Message the shop for larger batches.'], 422);
        }
        if ($service->min_order_qty > 1 && $quantity < $service->min_order_qty) {
            return response()->json([
                'success' => false,
                'message' => "This service requires a minimum of {$service->min_order_qty} pieces.",
                'errors' => ['quantity' => ["Order at least {$service->min_order_qty} pieces."]],
            ], 422);
        }

        // A service with several pricing lines needs one picked; a single line is implied.
        $pricing = null;
        if (! empty($validated['pricing_id'])) {
            $pricing = $service->pricing->firstWhere('id', (int) $validated['pricing_id']);
            if (! $pricing) {
                return response()->json(['success' => false, 'message' => 'That option is no longer offered. Please pick another.'], 422);
            }
        } elseif ($service->pricing->count() === 1) {
            $pricing = $service->pricing->first();
        } elseif ($service->pricing->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose an option for this service.',
                'errors' => ['pricing_id' => ['Choose an option.']],
            ], 422);
        }

        // Required service questions must be answered, and only known questions are kept.
        // Keyed by the question's label, same as booking answers (the job form pre-fills from them).
        $answers = [];
        $missing = [];
        foreach ($service->custom_fields ?? [] as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $value = trim((string) ($validated['answers'][$label] ?? ''));
            if ($value !== '') {
                $answers[$label] = $value;
            } elseif (! empty($field['required']) && ($field['type'] ?? '') !== 'checkbox') {
                $missing[] = $label;
            }
        }
        if ($missing) {
            return response()->json([
                'success' => false,
                'message' => 'Please answer: '.implode(', ', $missing).'.',
                'errors' => ['answers' => ['Some required questions are unanswered.']],
            ], 422);
        }

        $branch = ! empty($validated['store_branch_id'])
            ? $store->branches()->live()->where('id', $validated['store_branch_id'])->first()
            : null;
        if (! empty($validated['store_branch_id']) && ! $branch) {
            return response()->json(['success' => false, 'message' => 'That branch is not taking orders right now.'], 422);
        }
        $branch ??= $store->branches()->live()->where('is_main', true)->first()
            ?? $store->branches()->live()->first();

        $customerId = $request->user()->id;
        $measurementId = null;
        if (! empty($validated['measurement_id'])) {
            $measurementId = Measurement::where('id', $validated['measurement_id'])
                ->where('store_id', $store->id)
                ->where('customer_id', $customerId)
                ->value('id');
            if (! $measurementId) {
                return response()->json(['success' => false, 'message' => 'That measurement record is not on file with this shop.'], 422);
            }
        }

        $unitPrice = $pricing && $pricing->amount !== null ? (float) $pricing->amount : $service->effectivePrice();
        $totalAmount = round($unitPrice * $quantity, 2);

        $sheets = $validated['measurement_sheets'] ?? [];
        $customOrderData = array_filter([
            ...$answers,
            'contact_phone' => trim($validated['contact_phone']),
            'individual_measurements' => $measurements,
            'size_breakdown' => $sizeBreakdown,
            'measurement_sheets' => $sheets ?: null,
            'team_name' => $sizeBreakdown ? (trim((string) ($validated['team_name'] ?? '')) ?: null) : null,
            'size' => $sizeBreakdown ? null : ($validated['size'] ?? null),
            'option' => $pricing && $service->pricing->count() > 1 ? $pricing->label : null,
            'customer_fabric' => $validated['material_source'] === 'customer_supplied' ? ($validated['material_description'] ?? null) : null,
            'item_rate' => (string) $unitPrice,
            'payment_term' => $validated['payment_term'],
            // The customer asks; the shop decides whether to take it as rush and sets the fee.
            'rush_requested' => ! empty($validated['rush_requested']) ? 'Yes' : null,
            'needed_by' => ! empty($validated['needed_by']) ? \Carbon\Carbon::parse($validated['needed_by'])->toDateString() : null,
        ], fn ($v) => $v !== null && $v !== '');

        // What the chosen payment term asks for now: everything, or the service's deposit share.
        $requirements = \App\Support\OrderRequirements::resolve($store, [$service]);
        $amountDue = $validated['payment_term'] === 'full'
            ? $totalAmount
            : round($totalAmount * \App\Support\OrderRequirements::depositFraction($requirements['payment_policy'], $requirements['payment_policy_percent']), 2);

        $gcashAccounts = \App\Models\PaymentMethod::where('store_id', $store->id)
            ->where('is_active', true)
            ->where('kind', 'gcash')
            ->where(fn ($q) => $q->whereNull('store_branch_id')->when($branch, fn ($qq) => $qq->orWhere('store_branch_id', $branch->id)));

        $gcash = null;
        if ($amountDue > 0 && (clone $gcashAccounts)->exists()) {
            $gcash = ! empty($validated['payment_method_id'])
                ? (clone $gcashAccounts)->where('id', $validated['payment_method_id'])->first()
                : null;
            if (! $gcash) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pick the shop\'s GCash account you paid to.',
                    'errors' => ['payment_method_id' => ['Choose the GCash account.']],
                ], 422);
            }
            if (empty($validated['payment_receipt'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload a screenshot of your GCash receipt.',
                    'errors' => ['payment_receipt' => ['Upload your GCash receipt.']],
                ], 422);
            }
        }

        $orderNumber = $this->generateOrderNumber($store);
        $jobOrder = DB::transaction(function () use ($store, $orderNumber, $branch, $customerId, $service, $measurementId, $quantity, $validated, $totalAmount, $sheets, $customOrderData, $gcash, $amountDue, $request) {
            $jobOrder = $store->jobOrders()->create([
            'order_number' => $orderNumber,
            'tracking_code' => $this->generateTrackingCode($store, $orderNumber),
            'intake_channel' => 'online',
            'store_branch_id' => $branch?->id,
            'customer_id' => $customerId,
            'service_id' => $service->id,
            'measurement_id' => $measurementId,
            'quantity' => $quantity,
            'garment_category' => $service->service_category,
            'material_source' => $validated['material_source'],
            'total_amount' => $totalAmount,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'status' => 'pending',
            'notes' => ! empty($validated['notes']) ? 'Customer note: '.$validated['notes'] : null,
            // Sheets ride along with the reference photos, same as walk-in.
            'reference_images' => array_values(array_unique([...($validated['reference_images'] ?? []), ...$sheets])),
            'reference_link' => $validated['reference_link'] ?? null,
            'custom_order_data' => $customOrderData ?: null,
            ]);

            // Same record as "I have paid" on My Orders: pending until the shop verifies the receipt.
            if ($gcash) {
                $jobOrder->payments()->create([
                    'amount' => $amountDue,
                    'payment_method' => 'gcash',
                    'source' => 'online',
                    'type' => Payment::inferType($jobOrder, $amountDue),
                    'payment_method_id' => $gcash->id,
                    'reference' => $validated['payment_reference'] ?? null,
                    'recorded_by' => $request->user()->id,
                    'receipt_path' => $validated['payment_receipt'],
                ]);
            }

            return $jobOrder;
        });

        $jobOrder->load(['customer:id,name', 'service']);

        $store->customers()->syncWithoutDetaching([$customerId]);

        $this->notifyNewJobOrder($store, $jobOrder);

        $storeOwner = $store->owner;
        if ($storeOwner) {
            if ($gcash) {
                $storeOwner->notify(new PaymentReceivedNotification($jobOrder, $amountDue, true));
            }
        }

        return response()->json(['success' => true, 'data' => $jobOrder], 201);
    }

    public function index(Store $store, Request $request): JsonResponse
    {
        $query = $store->jobOrders()->with([
            'customer:id,name,suki_tag',
            'service',
            'assignedStaff:id,name,email',
            'assignedTo:id,name,email',
            'branch:id,name',
            'catalogItem:id,name,garment_type,price,fabric_image_url',
            'catalogItem.images',
        ]);

        $user = $request->user();
        $isOwnerOrManager = $user->hasRole('store_owner') || $user->hasRole('branch_manager');

        // RBAC: Staff/Tailors can only view orders specifically assigned to them
        if (! $isOwnerOrManager && $user->hasRole('staff')) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_staff_id', $user->id)
                    ->orWhere('assigned_to', $user->id);
            });
        }

        $branchId = null;
        if (! $user->hasRole('store_owner') && $user->staffProfile?->store_branch_id) {
            // Staff/branch managers pinned to a branch only ever see that branch's
            // jobs — matches the per-action branch check enforced elsewhere below,
            // so nothing shows up in the list that they'd then be blocked from opening.
            $branchId = $user->staffProfile->store_branch_id;
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
        }

        if ($branchId) {
            $query->where('store_branch_id', $branchId);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Used by the appointment Complete flow to look up a specific
        // customer's job orders (e.g. to link a Fitting/Pickup appointment)
        // without being limited to whatever page of the full store-wide list
        // happens to be loaded elsewhere.
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('assigned_staff_id')) {
            $query->where('assigned_staff_id', $request->assigned_staff_id);
        }

        // "My jobs": ones I'm attributed to (I moved them into a production stage) or hold as the assigned staff.
        if ($request->boolean('mine')) {
            $myUserId = $request->user()->id;
            $query->where(function ($q) use ($myUserId) {
                $q->where('assigned_staff_id', $myUserId)
                    ->orWhereIn('job_orders.id', DB::table('job_order_staff')->where('user_id', $myUserId)->select('job_order_id'));
            });
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        // The Jobs list page's own search box used to run entirely
        // client-side against the same per_page=200-capped fetch — a store
        // with more than 200 total historical jobs could search for a real
        // order number or customer name that exists beyond that window and
        // get "no results" even though it's genuinely there. Same
        // LOWER()+LIKE pattern as CatalogController::index's search — plain
        // LIKE isn't case-insensitive on Postgres the way it is on MySQL by
        // default, and this project has a Postgres migration planned.
        if ($request->filled('search')) {
            $search = strtolower((string) $request->string('search'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(order_number) LIKE ?', ['%'.$search.'%'])
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%']);
                    });
            });
        }

        // Used by the Payments page's "Job Balances" tab, which needs every
        // job still owing money regardless of production status. It used to
        // fetch the store's entire job history (per_page=500, paid jobs
        // included) just to filter down client-side — the same
        // undercounting risk as the other capped-array bugs fixed this
        // session, and wasteful besides. Filtering server-side means the
        // response only ever contains genuinely-relevant rows, which stay
        // naturally small for any store actually chasing its payments.
        if ($request->boolean('unpaid_only')) {
            $query->where('payment_status', '!=', 'paid')->where('status', '!=', 'cancelled');
        }

        // The Jobs list page's own tab badges ("All Orders 12", "Walk-in 8",
        // "Online 4") used to be re-derived client-side from this same list
        // fetched at per_page=200 — a store with more than 200 total
        // historical job orders (old completed/cancelled ones included,
        // nothing prunes them) would see those badges silently undercount.
        // Same fix as the Home dashboard's alert widgets: real, unbounded
        // counts, cloned off the already-filtered query before pagination
        // consumes it, so branch/status/customer/staff filters still apply
        // consistently to both the list and these counts.
        $walkInCount = (clone $query)->where('intake_channel', 'walk_in')->count();
        $onlineCount = (clone $query)->where('intake_channel', 'online')->count();
        // Same fix, same reasoning, for the "X job orders awaiting feasibility
        // review" banner (pending status).
        $pendingCount = (clone $query)->where('status', 'pending')->count();

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 15)),
            'walk_in_count' => $walkInCount,
            'online_count' => $onlineCount,
            'pending_count' => $pendingCount,
        ]);
    }

    /**
     * A job can only be given to staff of its own branch, or to staff with no
     * branch (they work across all branches). Someone assigned to another
     * branch (e.g. that branch's manager) is refused. Returns the 422 to send,
     * or null when the assignment is fine.
     */
    private function staffFromOtherBranch(Store $store, $staffUserId, $jobBranchId): ?JsonResponse
    {
        if (! $staffUserId || ! $jobBranchId) {
            return null;
        }
        $profile = $store->staff()->where('user_id', $staffUserId)->with('branch:id,name')->first();
        if ($profile && $profile->store_branch_id && (int) $profile->store_branch_id !== (int) $jobBranchId) {
            return response()->json([
                'success' => false,
                'message' => 'That staff member works at '.($profile->branch?->name ?? 'another branch').'. Pick someone from this order\'s branch.',
                'errors' => ['assigned_staff_id' => ['Staff from another branch can\'t take this order.']],
            ], 422);
        }

        return null;
    }

    public function store(StoreJobOrderRequest $request, Store $store): JsonResponse
    {
        $validated = $request->validated();
        // Set explicitly rather than relying on the DB column default — an
        // Eloquent model just-created from an array without this key never
        // gets it back until the row is re-fetched, so the create response
        // would silently omit "quantity": 1 even though that's what the row
        // actually holds.
        $validated['quantity'] = $validated['quantity'] ?? 1;

        // Server-side enforcement of service-type rules — the frontend already
        // checks these, but a client can't be trusted for money/liability-sensitive
        // rules like minimum order quantity or damage-waiver logging.
        $service = Service::find($validated['service_id']);
        if ($service) {
            // A bulk order's quantity comes from whichever shape it took —
            // a personalized team_roster (one row per piece), OR a plain
            // size_breakdown/total_quantity tally with no names at all
            // (e.g. "30 pcs department shirts"). Only checking team_roster
            // here would wrongly reject a perfectly valid size-breakdown
            // order against this service's minimum.
            $roster = $validated['custom_order_data']['team_roster'] ?? null;
            $bulkQty = is_array($roster) ? count($roster) : 0;
            if ($bulkQty === 0) {
                $bulkQty = (int) ($validated['custom_order_data']['total_quantity'] ?? 0);
            }

            // `quantity` (several identical pieces for one person) is a
            // different concept from a bulk order's roster (different
            // people). Force it to 1 for a bulk order regardless of what a
            // client sent — the frontend already guards this, but the
            // server shouldn't trust it, same reasoning as every other
            // check in this block.
            if ($service->hasType(Service::TYPE_BULK_SUBLIMATION)) {
                $validated['quantity'] = 1;
            }

            if (
                $service->hasType(Service::TYPE_BULK_SUBLIMATION)
                && $service->min_order_qty > 1
                && $bulkQty < $service->min_order_qty
            ) {
                return response()->json([
                    'success' => false,
                    'message' => "This service requires a minimum of {$service->min_order_qty} pieces.",
                ], 422);
            }

            if (
                $service->hasType(Service::TYPE_ALTERATION_REPAIR)
                && trim($validated['custom_order_data']['pre_existing_damage_notes'] ?? '') === ''
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pre-existing damage/condition notes are required for alteration and repair jobs.',
                ], 422);
            }
        }

        // Plain staff may only open a job order from an appointment of their own
        // shop and branch (the thesis flow: staff create job orders from scheduled
        // fittings). They never collect money or discount, so the order starts unpaid.
        $creator = $request->user();
        if (! $creator->hasRole('store_owner') && ! $creator->hasRole('branch_manager')) {
            $fromAppointment = $request->filled('appointment_id') ? Appointment::find($request->appointment_id) : null;
            $staffBranch = $creator->staffProfile?->store_branch_id;
            if (
                ! $fromAppointment
                || $fromAppointment->store_id !== $store->id
                || ! in_array($fromAppointment->status, ['confirmed', 'in_progress', 'completed'], true)
                || $fromAppointment->job_order_id
                || ($staffBranch && $fromAppointment->store_branch_id && (int) $staffBranch !== (int) $fromAppointment->store_branch_id)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff can create a job order from an approved appointment in their own branch, one per appointment.',
                ], 403);
            }
            $validated['balance'] = $validated['total_amount'];
            unset($validated['discount_amount'], $validated['payment_method']);
        }

        $validated['order_number'] = $this->generateOrderNumber($store);
        $validated['tracking_code'] = $this->generateTrackingCode($store, $validated['order_number']);
        $validated['order_type'] = $validated['order_type'] ?? 'walk_in';

        // Staff Assignment: Allow owners & managers to assign tailor directly during order creation
        $assignedStaffId = $validated['assigned_staff_id'] ?? $validated['assigned_to'] ?? null;
        $validated['assigned_staff_id'] = $assignedStaffId;
        $validated['assigned_to'] = $assignedStaffId;

        // An order made from an appointment belongs to that appointment's
        // branch, whichever branch the dashboard happens to be showing.
        if ($request->filled('appointment_id')) {
            $apptBranch = Appointment::where('store_id', $store->id)->whereKey($request->appointment_id)->value('store_branch_id');
            if ($apptBranch) {
                $validated['store_branch_id'] = $apptBranch;
            }
        }

        // Auto-assign branch when not explicitly set, falling back to the
        // creator's own branch (staff/branch manager).
        $staffProfile = $request->user()->staffProfile;
        if ($staffProfile && empty($validated['store_branch_id'])) {
            $validated['store_branch_id'] = $staffProfile->store_branch_id;
        }

        // The owner (no staffProfile) has no branch to inherit from the block
        // above, so a job created without explicitly picking one used to be
        // saved with store_branch_id = NULL — permanently invisible the moment
        // anyone filters the Production Pipeline to a specific branch, since
        // `WHERE store_branch_id = ?` never matches NULL in SQL. Confirmed live:
        // an owner-created job vanished from the board under the default
        // branch view with no error or warning. Default to the store's main
        // branch instead, same as any other job with no other signal.
        if (empty($validated['store_branch_id'])) {
            $mainBranch = $store->branches()->where('is_main', true)->first();
            if ($mainBranch) {
                $validated['store_branch_id'] = $mainBranch->id;
            }
        }

        if ($wrongBranch = $this->staffFromOtherBranch($store, $assignedStaffId, $validated['store_branch_id'] ?? null)) {
            return $wrongBranch;
        }

        // Determine payment status based on total amount and balance
        $totalAmount = (float) $validated['total_amount'];
        $balance = (float) $validated['balance'];
        $initialPayment = $totalAmount - $balance;

        if ($balance <= 0) {
            $validated['payment_status'] = 'paid';
        } elseif ($initialPayment > 0) {
            $validated['payment_status'] = 'partial';
        } else {
            $validated['payment_status'] = 'unpaid';
        }

        // Auto-calculates a starting rush fee when the owner/staff flags a job as
        // rush but doesn't type in their own figure — a sane default instead of a
        // blank field, never overriding an explicit value the caller actually
        // provided. Always editable afterward via the normal update() flow, same
        // as any other field — this only fills in a default.
        //
        // A repeat customer (3+ prior jobs at this store — the same signal already
        // shown on the Job Detail page as customer_job_count) gets a reduced rate
        // as a real loyalty perk. Deliberately NOT keyed off suki_tag: that field
        // holds a customer-type classification (b2b_suki/reseller/walk_in_retail),
        // not a repeat-customer flag, so it isn't the right signal for this.
        if (! empty($validated['is_rush']) && empty($validated['rush_fee']) && $totalAmount > 0) {
            $priorJobCount = JobOrder::where('store_id', $store->id)
                ->where('customer_id', $validated['customer_id'])
                ->count();
            $rushFeePercent = $priorJobCount >= 3 ? 0.15 : 0.30;
            $validated['rush_fee'] = round($totalAmount * $rushFeePercent, 2);
        }

        // Resolve the linked appointment (if any) before creating the job so its
        // design-reference photos/link — attached by the customer at booking time —
        // carry over onto the job the assigned staff actually work from, instead of
        // being stranded on an appointment record nobody looks at again.
        $appointment = null;
        if ($request->filled('appointment_id')) {
            $candidate = Appointment::find($request->appointment_id);
            if ($candidate && $candidate->store_id === $store->id) {
                $appointment = $candidate;
                if (empty($validated['reference_images']) && ! empty($appointment->reference_images)) {
                    $validated['reference_images'] = $appointment->reference_images;
                }
                if (empty($validated['reference_link']) && ! empty($appointment->reference_link)) {
                    $validated['reference_link'] = $appointment->reference_link;
                }
                if (empty($validated['garment_category']) && ! empty($appointment->garment_category)) {
                    $validated['garment_category'] = $appointment->garment_category;
                }
                if (empty($validated['catalog_item_id']) && ! empty($appointment->catalog_item_id)) {
                    $validated['catalog_item_id'] = $appointment->catalog_item_id;
                }
                if (empty($validated['service_package_id']) && ! empty($appointment->service_package_id)) {
                    $validated['service_package_id'] = $appointment->service_package_id;
                }
            }
        }

        // Server-authoritative intake channel — auto-tagged from the linked
        // appointment's own channel (online booking vs walk-in) instead of a
        // manual "How did they order?" toggle, which staff had to remember
        // to set correctly by hand. Any client-supplied intake_channel is
        // ignored on purpose.
        $validated['intake_channel'] = $appointment?->intake_channel === 'online' ? 'online' : 'walk_in';

        // Same idea as the appointment carry-over above, but for a Design Catalog
        // item the owner/staff explicitly linked at creation time (e.g. "make this
        // customer's order using this catalog design") — entirely optional, most
        // jobs are fully custom and have no catalog_item_id at all. Falls back to
        // the item's primary gallery photo, then its fabric swatch, so staff still
        // see a reference even for catalog items with no gallery images uploaded.
        if (! empty($validated['catalog_item_id'])) {
            $catalogItem = CatalogItem::with('images')->find($validated['catalog_item_id']);
            if ($catalogItem && $catalogItem->store_id === $store->id) {
                if (empty($validated['reference_images'])) {
                    $catalogImage = $catalogItem->images->firstWhere('is_primary', true)?->image_url
                        ?? $catalogItem->images->first()?->image_url
                        ?? $catalogItem->fabric_image_url;
                    if ($catalogImage) {
                        $validated['reference_images'] = [$catalogImage];
                    }
                }
                if (empty($validated['garment_category']) && ! empty($catalogItem->category)) {
                    $validated['garment_category'] = $catalogItem->category;
                }
            }
        }

        $jobOrder = $store->jobOrders()->create($validated);

        // Auto-save body measurements or measurement sheet photos directly into customer's profile
        \App\Support\JobMeasurementSync::syncForJob($store, $jobOrder);

        // Always record/sync customer in store's Client Book
        $store->customers()->syncWithoutDetaching([$jobOrder->customer_id]);

        // Link back to the appointment now that the job exists
        if ($appointment) {
            // 'outcome' has its own dedicated dropdown on the "Complete
            // Appointment" modal, but a job order is just as often created
            // straight from an appointment via this appointment_id linkage
            // without ever going through that modal — leaving outcome null
            // forever even though the appointment demonstrably *did*
            // convert. Set it here too so outcome-based reporting (booking
            // conversion rate) reflects real conversions regardless of
            // which of the two paths the owner used. Never overwrites an
            // outcome that's already set.
            $appointment->update([
                'job_order_id' => $jobOrder->id,
                'outcome' => $appointment->outcome ?? 'converted_to_job',
            ]);
            if ($appointment->status === 'pending') {
                $appointment->update(['status' => 'confirmed']);
            }
        }

        // Record initial payment history if downpayment occurred
        if ($initialPayment > 0) {
            $jobOrder->payments()->create([
                'amount' => $initialPayment,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'recorded_by' => $request->user()->id,
                'notes' => 'Initial downpayment recorded during order creation.',
            ]);
        }

        $jobOrder->load(['customer:id,name', 'service', 'servicePackage:id,name,bundle_price,service_category', 'servicePackage.services:id,name', 'assignedStaff:id,name', 'staffStages', 'catalogItem:id,name,fabric_image_url', 'catalogItem.images']);

        // Notify the store owner and this branch's managers of the new job order
        $this->notifyNewJobOrder($store, $jobOrder);

        // Notify assigned tailor of the job order assignment
        if (! empty($jobOrder->assigned_staff_id)) {
            $assignedStaff = User::find($jobOrder->assigned_staff_id);
            if ($assignedStaff) {
                $assignedStaff->notify(new JobOrderAssignedNotification($jobOrder, $request->user()));
                Cache::driver('file')->forget("user_notifications_{$assignedStaff->id}");
            }
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder,
        ], 201);
    }

    public function show(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $user = $request->user();
        $isOwnerOrManager = $user->hasRole('store_owner') || $user->hasRole('branch_manager');

        // RBAC: Staff/Tailors can only access orders assigned to them
        if (! $isOwnerOrManager && $user->hasRole('staff')) {
            if ((int) $jobOrder->assigned_staff_id !== (int) $user->id && (int) $jobOrder->assigned_to !== (int) $user->id) {
                return response()->json(['success' => false, 'message' => 'Unauthorized. You are only allowed to access orders assigned to you.'], 403);
            }
        }

        $jobOrder->load(['customer', 'service', 'assignedStaff:id,name,email', 'assignedTo:id,name,email', 'measurement', 'staffStages', 'payments.recordedBy:id,name', 'catalogItem:id,name,fabric_image_url', 'catalogItem.images', 'branch:id,name']);

        // Repeat-customer context surfaced right on the job so the owner can
        // decide on a manual discount ("this is their 5th order") without
        // having to jump to the Customers page first.
        $jobOrder->customer_job_count = $jobOrder->customer_id
            ? JobOrder::where('store_id', $store->id)->where('customer_id', $jobOrder->customer_id)->count()
            : 0;

        // A job order pins measurement_id at creation and nothing ever
        // re-points it — Measurement::update() always closes out the old
        // version and creates a new one (see MeasurementController@update),
        // so a correction made after this job started (e.g. staff re-measures
        // at fitting and fixes a wrong waist) never reaches a job already in
        // production unless someone notices and manually re-links it. Surface
        // that gap here instead of leaving it silent: tell the frontend
        // whether a newer version exists and what its id is.
        if ($jobOrder->measurement) {
            $currentVersion = Measurement::where('store_id', $store->id)
                ->where('customer_id', $jobOrder->measurement->customer_id)
                ->where('profile_name', $jobOrder->measurement->profile_name)
                ->whereNull('superseded_at')
                ->first();
            $jobOrder->measurement->is_stale = $currentVersion && $currentVersion->id !== $jobOrder->measurement->id;
            $jobOrder->measurement->current_version_id = $currentVersion?->id;
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder,
        ]);
    }

    public function update(UpdateJobOrderRequest $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $user = $request->user();
        $isOwnerOrManager = $user->hasRole('store_owner') || $user->hasRole('branch_manager');

        // RBAC: Staff/Tailors can only edit jobs assigned to them
        if (! $isOwnerOrManager && $user->hasRole('staff')) {
            if ((int) $jobOrder->assigned_staff_id !== (int) $user->id && (int) $jobOrder->assigned_to !== (int) $user->id) {
                return response()->json(['success' => false, 'message' => 'Unauthorized. You can only update jobs assigned to you.'], 403);
            }
        }

        $oldStatus = $jobOrder->status;
        $oldAssignedStaffId = $jobOrder->assigned_staff_id ?? $jobOrder->assigned_to;
        $validated = $request->validated();
        $newStatus = $validated['status'] ?? null;

        // Staff members are prohibited from reassigning jobs
        if (! $isOwnerOrManager && $user->hasRole('staff')) {
            unset($validated['assigned_staff_id'], $validated['assigned_to']);
        } elseif ($isOwnerOrManager) {
            // Keep assigned_staff_id and assigned_to in sync when owner/manager assigns tailor
            if (array_key_exists('assigned_staff_id', $validated) || array_key_exists('assigned_to', $validated)) {
                $assignedStaffId = $validated['assigned_staff_id'] ?? $validated['assigned_to'] ?? null;
                $validated['assigned_staff_id'] = $assignedStaffId;
                $validated['assigned_to'] = $assignedStaffId;

                if ($wrongBranch = $this->staffFromOtherBranch($store, $assignedStaffId, $jobOrder->store_branch_id)) {
                    return $wrongBranch;
                }
            }
        }

        // Same auto-calculated rush fee default as store() (including the
        // repeat-customer discount) — only fills in a starting figure when
        // is_rush is being newly turned on here without the caller also
        // supplying their own rush_fee; never overrides an explicit value,
        // and never touches a job that was already rush.
        if (
            ! empty($validated['is_rush']) && ! $jobOrder->is_rush
            && ! isset($validated['rush_fee']) && (float) $jobOrder->total_amount > 0
        ) {
            $priorJobCount = JobOrder::where('store_id', $jobOrder->store_id)
                ->where('customer_id', $jobOrder->customer_id)
                ->count();
            $rushFeePercent = $priorJobCount >= 3 ? 0.15 : 0.30;
            $validated['rush_fee'] = round((float) $jobOrder->total_amount * $rushFeePercent, 2);
        }

        // Server-side backstop for the same "No DP, No Cut" / "No Balance, No Claim"
        // rules the Kanban UI enforces — this endpoint is also reachable from the
        // Job Detail page's own status dropdown, so the rule has to live here too,
        // not just in one frontend component.
        if ($newStatus && $newStatus !== $oldStatus) {
            $totalAmount = (float) $jobOrder->total_amount;
            // A discount lowers balance, not total, so it must be subtracted
            // here too — otherwise a discount counts as cash paid and a
            // discounted job can pass the 50% gate without enough payment.
            $discount = (float) $jobOrder->discount_amount;
            $paidSoFar = $totalAmount - (float) $jobOrder->balance - $discount;
            $amountDue = $totalAmount - $discount;

            // "No DP, No Layout, No Cut": matches the 50% downpayment policy
            // shown on the Job Detail page and Kanban board. 'pending' and
            // 'design' are deliberately excluded — no fabric or material is
            // committed yet at those stages, only once Pattern Making (or
            // its Bulk Order Override, Mass Cutting & Printing) starts.
            // Repairs skip this unless the shop opted in (requiresDownpaymentFor).
            // The required share is this job's snapshotted payment policy
            // (none / full / deposit / custom percent), not a fixed 50%.
            $fraction = $jobOrder->requiredDepositFraction();
            if ($jobOrder->requiresDownpaymentFor($newStatus) && $amountDue > 0 && $paidSoFar + 0.005 < ($amountDue * $fraction)) {
                $label = $fraction >= 1 ? 'The full payment' : 'A '.(int) round($fraction * 100).'% downpayment';
                return response()->json([
                    'success' => false,
                    'message' => $label.' must be collected before production can start on this job.',
                ], 422);
            }

            // Final fitting, per this job's snapshotted requirement. 'none' means the
            // shop does not fit this order, so it never enters the fitting stage;
            // 'required' means it cannot be handed over before a fitting is done.
            if ($newStatus === 'ready_for_fitting' && $jobOrder->fitting_requirement === 'none') {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not need a final fitting. Move it to QC / Ironing or Ready for Pickup instead.',
                ], 422);
            }
            if (
                in_array($newStatus, ['ready_for_pickup', 'completed'], true)
                && $jobOrder->fitting_requirement === 'required'
                && ! $jobOrder->appointments()->where('appointment_type', 'fitting')->where('status', 'completed')->exists()
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'A final fitting is required for this order. Complete the fitting appointment before handing it over.',
                ], 422);
            }

            if ($newStatus === 'completed' && (float) $jobOrder->balance > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'The remaining balance must be paid before this job can be marked completed.',
                ], 422);
            }

            // Completion photo upload stays available (upload → column →
            // print ticket) but is no longer a hard gate on reaching Ready
            // for Pickup — per explicit owner request, it's optional, not
            // required. Was previously enforced here ("No QC Photo, No
            // Ready for Pickup"); removed rather than left as dead
            // commented-out logic.

            // 'rejected' has its own dedicated endpoint (JobOrderController@rejectOrder)
            // with rules this generic endpoint doesn't enforce: owner/branch_manager
            // only (staff can reach this route), pending-only, and a required reason.
            // Blocking it here closes off a bypass of all three via a plain status edit.
            if ($newStatus === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Use the dedicated reject endpoint to decline a pending order.',
                ], 422);
            }

            // Fitting limit, 'block' policy — the counterpart to the 'fee'
            // policy's charge below (Phase 3 block, after save). Checked
            // pre-save, same style as the DP gate above, so a blocked
            // transition never partially applies. 'fee' policy (default)
            // is unaffected here; it's still handled after save, where the
            // auto-fitting appointment actually gets created.
            if ($newStatus === 'ready_for_fitting' && $oldStatus !== 'ready_for_fitting' && $store->fitting_limit_policy === 'block') {
                $priorFittingCount = $jobOrder->appointments()->where('appointment_type', 'fitting')->count();
                if ($store->fitting_limit && $priorFittingCount >= $store->fitting_limit) {
                    return response()->json([
                        'success' => false,
                        'message' => "This job has already used its {$store->fitting_limit} included fitting session(s), and this shop does not allow additional fittings beyond the limit. Move it to QC/Ironing or Ready for Pickup instead, or contact the customer directly.",
                    ], 422);
                }
            }
        }

        // Measurement snapshot lock — mirrors the "No DP, No Cut" boundary
        // above (STAGES_REQUIRING_DOWNPAYMENT = production has actually
        // started). Before that point (pending/design/queued), re-pointing
        // measurement_id is the "Sync with Current Version" action's normal
        // job — freely switching to whatever the customer's latest profile
        // is. Once production has started, the whole point of pinning
        // measurement_id at creation is that it stays the exact snapshot
        // cutting/sewing was done against; letting it silently swap out
        // mid-job (even to a "newer" version) would cut/sew to numbers that
        // no longer match what was actually already worked. Checked against
        // $oldStatus (the job's status *before* this request), not
        // $newStatus, so a request that simultaneously advances the job
        // into production can't launder a stale-snapshot swap through the
        // same call.
        if (
            array_key_exists('measurement_id', $validated)
            && (int) ($validated['measurement_id'] ?? 0) !== (int) $jobOrder->measurement_id
            && in_array($oldStatus, JobOrder::STAGES_REQUIRING_DOWNPAYMENT, true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This job has already started production — its measurement snapshot is locked and can no longer be switched to a different version.',
            ], 422);
        }

        $jobOrder->update($validated);

        // Staff Assignment notification & audit log
        $newAssignedStaffId = $jobOrder->assigned_staff_id ?? $jobOrder->assigned_to;
        $staffAssignmentChanged = (array_key_exists('assigned_staff_id', $validated) || array_key_exists('assigned_to', $validated))
            && (int) ($oldAssignedStaffId ?? 0) !== (int) ($newAssignedStaffId ?? 0);

        if ($staffAssignmentChanged) {
            $store->auditLogs()->create([
                'user_id' => $user->id,
                'action' => 'job_staff_assigned',
                'model_type' => JobOrder::class,
                'model_id' => $jobOrder->id,
                'payload' => [
                    'assigned_staff_id' => $newAssignedStaffId,
                    'previous_staff_id' => $oldAssignedStaffId,
                ],
                'ip_address' => $request->ip(),
            ]);

            if ($newAssignedStaffId) {
                $assignee = User::find($newAssignedStaffId);
                if ($assignee) {
                    $assignee->notify(new JobOrderAssignedNotification($jobOrder, $user));
                    Cache::driver('file')->forget("user_notifications_{$assignee->id}");
                }
            }
        }

        // Auto-sync measurements if updated in custom_order_data
        \App\Support\JobMeasurementSync::syncForJob($store, $jobOrder);

        // Auto-derived production attribution: replaces the old "owner
        // manually pre-assigns a staff member to a stage before they can
        // touch it" model. Any staff member at the matching branch could
        // already update a job's status regardless of assignment (this
        // endpoint never checked staffStages before allowing it) — this
        // just makes that reality visible instead of requiring a separate
        // supervisory step first. Whoever actually moves the job into a
        // production stage is recorded as having worked that stage; whoever
        // moves it back OUT of one closes that stage's completed_at. Same
        // job_order_staff table and shape the Staff Productivity report
        // already reads (JobOrderStaff/StaffController::index) — jobs
        // touched, completion rate — nothing there needed to change, it's
        // just populated automatically now instead of via assignStaff().
        if ($newStatus && $newStatus !== $oldStatus) {
            $stageFor = fn (?string $status) => $status === 'mass_cutting_printing' ? 'pattern_making' : $status;
            $oldStage = $stageFor($oldStatus);
            $newStage = $stageFor($newStatus);
            $actorId = $request->user()->id;

            if ($oldStage && in_array($oldStage, JobOrder::STAFF_STAGES, true)) {
                DB::table('job_order_staff')
                    ->where('job_order_id', $jobOrder->id)
                    ->where('stage', $oldStage)
                    ->whereNull('completed_at')
                    ->update(['completed_at' => now()]);
            }

            if ($newStage && in_array($newStage, JobOrder::STAFF_STAGES, true)) {
                $existingId = DB::table('job_order_staff')
                    ->where('job_order_id', $jobOrder->id)
                    ->where('stage', $newStage)
                    ->value('id');

                if ($existingId) {
                    // completed_at reset to null too — covers a job re-entering
                    // a stage it already finished once before (e.g. a revert
                    // during final_adjustments rework), so a second real visit
                    // to this stage isn't shown as already completed.
                    DB::table('job_order_staff')->where('id', $existingId)->update(['user_id' => $actorId, 'completed_at' => null]);
                } else {
                    DB::table('job_order_staff')->insert([
                        'job_order_id' => $jobOrder->id,
                        'user_id' => $actorId,
                        'stage' => $newStage,
                        'assigned_at' => now(),
                        'completed_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Attribute assigned_staff_id only if unassigned and actor is staff
            if (! $jobOrder->assigned_staff_id && $user->hasRole('staff')) {
                $jobOrder->update(['assigned_staff_id' => $actorId, 'assigned_to' => $actorId]);
            }
        }

        // Cancelling a job leaves its linked fitting appointment(s) dangling
        // otherwise — still 'pending'/'confirmed' on the calendar for a job
        // that no longer exists, which staff could show up for, and which
        // (once confirmed) blocks that time slot for a real future booking.
        // Confirmed live: cancelling JO-1005 left appointment #35 sitting at
        // 'pending' with no connection back to anything now dead.
        if ($jobOrder->status === 'cancelled' && $oldStatus !== 'cancelled') {
            $jobOrder->appointments()
                ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
                ->get()
                ->each(function (Appointment $appointment) use ($request) {
                    $appointment->update(['status' => 'cancelled']);
                    $appointment->customer?->notify(new AppointmentStatusNotification($appointment, 'cancelled', null, $request->user()));
                });
        }

        // Tracks how many times a job has entered Final Adjustments, and when
        // the first one happened — lets the owner judge the store's own "1 free
        // adjustment within N days" policy manually (the exact policy varies
        // store to store, so this only surfaces the facts rather than enforcing
        // one hard-coded rule). Neither field is client-settable (not in
        // $fillable) — both are server-derived from the status transition itself.
        if ($jobOrder->status === 'final_adjustments' && $oldStatus !== 'final_adjustments') {
            $jobOrder->increment('adjustment_count');
            if (! $jobOrder->first_adjustment_at) {
                $jobOrder->forceFill(['first_adjustment_at' => now()])->save();
            }
        }

        // When the OWNER marks a job completed, stamp completion on its staff
        // assignments so "jobs completed" / productivity is derivable without a staff portal.
        if ($jobOrder->status === 'completed' && $oldStatus !== 'completed') {
            DB::table('job_order_staff')
                ->where('job_order_id', $jobOrder->id)
                ->whereNull('completed_at')
                ->update(['completed_at' => now()]);
            // Server-derived, like ready_for_pickup_at: the Completed Orders
            // page's completion date and handling time read this, not updated_at
            // (which any later edit, e.g. a payment fix, would move).
            $jobOrder->forceFill(['completed_at' => now()])->save();
        } elseif ($oldStatus === 'completed' && $jobOrder->status !== 'completed' && $jobOrder->completed_at) {
            $jobOrder->forceFill(['completed_at' => null])->save();
        }

        // Same aging-alert pattern as ready_for_pickup_at below — on_hold is
        // correctly excluded from the overdue_jobs KPI (the owner paused it
        // on purpose, it's not "late"), but that left it with zero aging
        // visibility anywhere at all. Cleared back to null the moment the
        // job leaves on_hold, so a job that gets held twice gets a fresh
        // clock each time, not the first hold's stale timestamp.
        if ($jobOrder->status === 'on_hold' && $oldStatus !== 'on_hold') {
            $jobOrder->forceFill(['held_at' => now()])->save();
        } elseif ($oldStatus === 'on_hold' && $jobOrder->status !== 'on_hold' && $jobOrder->held_at) {
            $jobOrder->forceFill(['held_at' => null])->save();
        }

        if ($jobOrder->status === 'ready_for_pickup' && $oldStatus !== 'ready_for_pickup') {
            // Server-derived, not client-settable — start of the "unclaimed
            // pickup" aging clock the Reports page now surfaces, so an item
            // sitting on the rack gets flagged before it becomes a forfeited
            // deposit (cancellation_reason=forfeited_deposit_abandoned) instead
            // of only after.
            $jobOrder->forceFill(['ready_for_pickup_at' => now()])->save();
            $jobOrder->customer->notify(new OrderReadyNotification($jobOrder, $request->user()));
            $this->notifyOwnerOfActivity($request, $store, [
                'type' => 'job_ready_for_pickup',
                'title' => 'Job Order Ready for Pickup',
                'message' => "{$jobOrder->order_number} ({$jobOrder->customer?->name}) is ready for pickup.",
                'url' => '/dashboard/jobs/'.$jobOrder->id,
                'extra' => ['job_order_id' => $jobOrder->id, 'order_number' => $jobOrder->order_number],
            ], $jobOrder);
        }

        // Phase 3: "Ready for Fitting" auto-triggers a Fitting appointment for
        // the customer instead of the owner having to remember to book one
        // manually. Guarded against duplicates so looping back through
        // Final Adjustments and forward again doesn't spawn a second one —
        // the placeholder date/time is a rough default the owner/staff is
        // expected to actually reschedule with the customer.
        if ($jobOrder->status === 'ready_for_fitting' && $oldStatus !== 'ready_for_fitting') {
            // Locking the job order row for the duration of the check+create
            // serializes two near-simultaneous transitions into
            // 'ready_for_fitting' for the same job — without this, both
            // requests could pass the "no open fitting exists yet" check
            // before either INSERT commits, spawning two auto-fitting
            // appointments instead of one.
            DB::transaction(function () use ($store, $jobOrder) {
                JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $hasOpenFitting = $jobOrder->appointments()
                    ->where('appointment_type', 'fitting')
                    ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
                    ->exists();

                if (! $hasOpenFitting) {
                    // Fitting session limit — the store's fitting_limit is how many
                    // fitting appointments a job gets before fitting_limit_policy
                    // kicks in: 'fee' (default, handled below) or 'block' (the
                    // pre-save gate above already rejected the transition, so this
                    // code only ever runs here under the 'fee' policy — not a
                    // rental concept; every prior fitting counts toward it,
                    // completed or not, since a no-show/cancelled session still
                    // used the store's time). A job cycling ready_for_fitting →
                    // final_adjustments → ready_for_fitting again is exactly the
                    // real scenario this exists for: a second fitting round after
                    // adjustments.
                    $priorFittingCount = $jobOrder->appointments()->where('appointment_type', 'fitting')->count();
                    $overFittingLimit = $store->fitting_limit && $priorFittingCount >= $store->fitting_limit;

                    // "Tomorrow at 10am" is just a rough placeholder the owner
                    // is expected to reschedule with the customer anyway — but
                    // landing it on a day the store announced as closed (see
                    // Store::closureTitleOn, also enforced for real bookings in
                    // AppointmentController) would still look like a real bug
                    // to whoever opens this appointment first. Walk forward to
                    // the next open day instead, capped so a long closure
                    // can't spin this into an unbounded loop.
                    $placeholderDate = now()->addDay()->setTime(10, 0);
                    for ($i = 0; $i < 30 && $store->closureTitleOn($placeholderDate, $jobOrder->store_branch_id); $i++) {
                        $placeholderDate = $placeholderDate->copy()->addDay();
                    }

                    $jobOrder->appointments()->create([
                        'store_id' => $store->id,
                        'store_branch_id' => $jobOrder->store_branch_id,
                        'customer_id' => $jobOrder->customer_id,
                        'appointment_type' => 'fitting',
                        'intake_channel' => 'walk_in',
                        'scheduled_at' => $placeholderDate,
                        'duration_minutes' => Appointment::TYPE_DEFAULT_DURATIONS['fitting'],
                        'status' => 'pending',
                        'notes' => "Auto-generated when Job Order {$jobOrder->order_number} became Ready for Fitting — please confirm the actual date/time with the customer."
                            .($overFittingLimit ? " This is fitting #{$priorFittingCount}+1, past the store's {$store->fitting_limit}-session limit — a ₱{$store->fitting_fee} fitting fee has been added to the balance." : ''),
                    ]);

                    if ($overFittingLimit && $store->fitting_fee > 0) {
                        $jobOrder->increment('total_amount', $store->fitting_fee);
                        $jobOrder->increment('balance', $store->fitting_fee);
                        if ($jobOrder->payment_status === 'paid') {
                            $jobOrder->update(['payment_status' => 'partial']);
                        }
                    }
                }
            });
        }

        // Matches the thesis's own scope objective — customers "receive automated
        // SMS or email notifications at each stage transition, from placement to
        // final pickup." ready_for_pickup keeps its dedicated notification above;
        // every other production/fulfillment stage is covered here. Derived from
        // JobOrder::STATUSES (rather than a hand-kept literal list) so a future
        // pipeline status is notifiable by default instead of silently excluded.
        $otherNotifiableStatuses = array_diff(JobOrder::STATUSES, ['pending', 'ready_for_pickup']);
        if (in_array($jobOrder->status, $otherNotifiableStatuses, true) && $jobOrder->status !== $oldStatus) {
            $notifStatus = ($oldStatus === 'pending' && in_array($jobOrder->status, ['production', 'design'], true)) ? 'approved' : $jobOrder->status;
            $jobOrder->customer?->notify(new JobStatusUpdatedNotification($jobOrder, $notifStatus, $request->user()));
            $this->notifyOwnerOfActivity($request, $store, [
                'type' => 'job_'.$jobOrder->status,
                'title' => 'Job Order Updated',
                'message' => "{$jobOrder->order_number} ({$jobOrder->customer?->name}) moved to ".str_replace('_', ' ', $jobOrder->status).'.',
                'url' => '/dashboard/jobs/'.$jobOrder->id,
                'extra' => ['job_order_id' => $jobOrder->id, 'order_number' => $jobOrder->order_number],
            ], $jobOrder);
        }

        return response()->json([
            'success' => true,
            'data' => $jobOrder,
        ]);
    }

    public function pay(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            // The job's cashier desk takes cash or GCash only.
            'payment_method' => 'sometimes|string|in:cash,gcash',
            // Where it was made and what it is for — both optional (type is worked out when absent).
            'source' => 'sometimes|in:'.implode(',', \App\Models\Payment::SOURCES),
            'type' => 'sometimes|nullable|in:'.implode(',', \App\Models\Payment::TYPES),
            'payment_method_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('payment_methods', 'id')->where('store_id', $store->id)],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            // A GCash/PayMaya payment is the artifact being verified against —
            // required, not optional, now that it actually gates something
            // (see the pending-verification handling below). Cash needs no
            // proof: the money is physically in hand at the counter.
            'receipt_path' => [\Illuminate\Validation\Rule::requiredIf(fn () => $request->input('payment_method', 'cash') !== 'cash'), 'nullable', 'string', 'max:2048'],
            'cash_tendered' => [\Illuminate\Validation\Rule::requiredIf(fn () => $request->input('payment_method', 'cash') === 'cash'), 'nullable', 'numeric', 'min:0'],
        ]);

        $paymentMethod = $validated['payment_method'] ?? 'cash';
        $paymentAmount = (float) $validated['amount'];

        $cashTendered = null;
        $changeAmount = null;
        if ($paymentMethod === 'cash') {
            $cashTendered = (float) $validated['cash_tendered'];
            if ($cashTendered < $paymentAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cash tendered cannot be less than the amount being paid.',
                ], 422);
            }
            $changeAmount = round($cashTendered - $paymentAmount, 2);
        }

        // A GCash/bank reference number reused across two different orders
        // is exactly what a reused-screenshot scam looks like — the same
        // proof-of-payment submitted twice to get credited twice. Warns
        // rather than blocks: legitimate reference collisions do happen
        // (some banks' reference formats aren't globally unique), so this
        // surfaces the fact for the staff member logging it to judge, the
        // same "surface facts, don't hard-code a rule" approach already
        // used for adjustment_count.
        $duplicateReferenceWarning = null;
        if (! empty($validated['reference'])) {
            $duplicateOrder = JobOrder::where('store_id', $store->id)
                ->whereHas('payments', function ($q) use ($validated) {
                    $q->where('reference', $validated['reference'])->whereNull('rejected_at');
                })
                ->first();
            if ($duplicateOrder) {
                $duplicateReferenceWarning = "This reference number was already used on order {$duplicateOrder->order_number} — double-check this isn't a reused receipt before accepting.";
            }
        }

        $isCash = $paymentMethod === 'cash';

        try {
            // Lock the row for the duration of the transaction so two payments
            // submitted at nearly the same time (e.g. two staff on the same job)
            // can't both read the same starting balance and overpay/double-count.
            DB::transaction(function () use ($jobOrder, $paymentAmount, $paymentMethod, $isCash, $cashTendered, $changeAmount, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                // Cash is verified on the spot — the money is already in the
                // till, so it applies to the balance immediately, same as
                // before this change. GCash/PayMaya only ever prove a screenshot
                // was uploaded, not that the money actually landed, so those
                // stay unapplied (balance untouched) until an owner/branch
                // manager calls verifyPayment() below.
                if ($isCash) {
                    $currentBalance = (float) $locked->balance;
                    if ($paymentAmount > $currentBalance) {
                        throw new \RuntimeException('Payment exceeds remaining balance');
                    }

                    $newBalance = round($currentBalance - $paymentAmount, 2);
                    $locked->update([
                        'balance' => $newBalance,
                        'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
                    ]);
                } else {
                    // Still has to fit under the current balance so a customer
                    // can't "pay" more than what's owed even before verification.
                    if ($paymentAmount > (float) $locked->balance) {
                        throw new \RuntimeException('Payment exceeds remaining balance');
                    }
                }

                $locked->payments()->create([
                    'amount' => $paymentAmount,
                    'payment_method' => $paymentMethod,
                    'source' => $validated['source'] ?? 'walk_in',
                    'type' => $validated['type'] ?? \App\Models\Payment::inferType($locked, $paymentAmount),
                    'payment_method_id' => $validated['payment_method_id'] ?? null,
                    'reference' => $validated['reference'] ?? null,
                    'recorded_by' => $request->user()->id,
                    'notes' => $validated['notes'] ?? null,
                    'receipt_path' => $validated['receipt_path'] ?? null,
                    'cash_tendered' => $cashTendered,
                    'change_amount' => $changeAmount,
                    'verified_at' => $isCash ? now() : null,
                    'verified_by' => $isCash ? $request->user()->id : null,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        // Notify store owner of the payment — a GCash/PayMaya one still needs
        // their (or a branch manager's) action to verify it, so the message
        // makes that explicit instead of reading like a done deal.
        $storeOwner = $store->owner;
        if ($storeOwner) {
            $storeOwner->notify(new PaymentReceivedNotification($jobOrder, $paymentAmount, ! $isCash));
        }

        return response()->json([
            'success' => true,
            'message' => $isCash ? 'Payment logged successfully' : 'Payment submitted — pending verification before it applies to the balance.',
            'warning' => $duplicateReferenceWarning,
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'payments.verifiedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * Owner/branch-manager-only confirmation that a GCash/PayMaya payment
     * actually landed (the counterpart to cash, which auto-verifies at
     * creation since the money is already in hand). Only once this fires
     * does the payment apply to the job's balance/payment_status — mirrors
     * pay()'s own locked-transaction math, just deferred.
     */
    public function verifyPayment(Request $request, Store $store, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        try {
            DB::transaction(function () use ($jobOrder, $payment, $request) {
                $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

                if ($lockedPayment->rejected_at !== null) {
                    throw new \RuntimeException('This payment was already rejected and can no longer be verified.');
                }
                if ($lockedPayment->verified_at !== null) {
                    throw new \RuntimeException('This payment is already verified.');
                }

                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $currentBalance = (float) $locked->balance;
                $amount = (float) $lockedPayment->amount;
                if ($amount > $currentBalance) {
                    throw new \RuntimeException('This payment amount no longer fits the remaining balance — it may have been overtaken by another payment since it was submitted.');
                }

                $newBalance = round($currentBalance - $amount, 2);
                $locked->update([
                    'balance' => $newBalance,
                    'payment_status' => $newBalance <= 0 ? 'paid' : 'partial',
                ]);

                $lockedPayment->update([
                    'verified_at' => now(),
                    'verified_by' => $request->user()->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment verified.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'payments.verifiedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * A one-time, in-the-moment discount the owner grants on an existing job
     * (e.g. a repeat customer) — not a standing coupon/promo code. Reduces
     * the remaining balance directly and is logged to the audit trail, same
     * pattern as AppointmentController's reschedule/complete audit entries.
     */
    public function applyDiscount(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        $discountAmount = (float) $validated['amount'];

        try {
            DB::transaction(function () use ($jobOrder, $discountAmount, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                $currentBalance = (float) $locked->balance;
                if ($discountAmount > $currentBalance) {
                    throw new \RuntimeException('Discount cannot exceed the remaining balance (₱'.number_format($currentBalance, 2).').');
                }

                $newBalance = round($currentBalance - $discountAmount, 2);
                $newDiscountTotal = round((float) ($locked->discount_amount ?? 0) + $discountAmount, 2);

                $locked->update([
                    'balance' => $newBalance,
                    'discount_amount' => $newDiscountTotal,
                    'payment_status' => $newBalance <= 0 ? 'paid' : $locked->payment_status,
                ]);

                $store = $locked->store;
                $store->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'discount_applied',
                    'model_type' => JobOrder::class,
                    'model_id' => $locked->id,
                    'payload' => [
                        'amount' => $discountAmount,
                        'reason' => $validated['reason'] ?? null,
                    ],
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Discount applied successfully.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }

    // Corrects a payment's metadata (method/reference/receipt/notes) without
    // touching `amount` — the amount is what the job's balance was already
    // calculated from, so it stays permanently immutable here. If a walk-in
    // payment was logged with the wrong reference number or receipt image,
    // this fixes the record instead of requiring a reversal/re-entry that
    // would distort the payment trail.
    public function updatePayment(Request $request, Store $store, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // Once a job is completed (garment delivered/picked up), its payment
        // records are final — matching the same "never silently rewrite the
        // payment trail" principle already applied elsewhere (amount is
        // always locked; this closes the remaining loophole where method/
        // reference/notes could still be edited after the fact).
        if ($jobOrder->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'This job is already completed — its payment records can no longer be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'sometimes|string|in:cash,gcash',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'receipt_path' => 'nullable|string|max:2048',
        ]);

        $payment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * Reject a specific payment discovered to be fraudulent/bad after the
     * fact (e.g. a fake GCash receipt caught during a later review — staff
     * couldn't have known at the time). Reverses the balance/payment_status
     * regardless of the job's current production status: the "no balance,
     * no claim" rule in update() only blocks *advancing to* completed with a
     * balance owed, it doesn't forbid a completed job from later having a
     * balance again — that state becoming possible is exactly how a fraud
     * discovered after the garment was already handed over becomes visible.
     */
    public function rejectPayment(Request $request, Store $store, JobOrder $jobOrder, Payment $payment): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id || $payment->job_order_id !== $jobOrder->id) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            DB::transaction(function () use ($jobOrder, $payment, $validated, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

                // Lock the Payment row too, and re-check idempotency on the
                // freshly-locked instance — checking the outer $payment
                // parameter (loaded before the lock, at request start) would
                // leave a TOCTOU window where two concurrent requests both
                // read rejected_at === null and both apply the reversal.
                $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

                if ($lockedPayment->rejected_at !== null) {
                    throw new \RuntimeException('This payment has already been rejected.');
                }

                // A payment still pending verification (an unconfirmed GCash/
                // PayMaya screenshot) was never applied to the balance in the
                // first place — pay() left it untouched on purpose. Reversing
                // it here too would double-credit the balance for money that
                // was never actually counted as received.
                if ($lockedPayment->verified_at !== null) {
                    $newBalance = round((float) $locked->balance + (float) $lockedPayment->amount, 2);
                    $hasOtherConfirmedPayments = $locked->payments()
                        ->whereNull('rejected_at')
                        ->whereNotNull('verified_at')
                        ->where('id', '!=', $lockedPayment->id)
                        ->exists();
                    $newPaymentStatus = $newBalance <= 0
                        ? 'paid'
                        : ($hasOtherConfirmedPayments ? 'partial' : 'unpaid');

                    $locked->update([
                        'balance' => $newBalance,
                        'payment_status' => $newPaymentStatus,
                    ]);
                }

                $lockedPayment->update([
                    'rejected_at' => now(),
                    'rejected_reason' => $validated['reason'],
                    'rejected_by' => $request->user()->id,
                ]);

                $store = $locked->store;
                $store->auditLogs()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'payment_rejected',
                    'model_type' => Payment::class,
                    'model_id' => $lockedPayment->id,
                    'payload' => [
                        'job_order_id' => $locked->id,
                        'amount' => (float) $lockedPayment->amount,
                        'reason' => $validated['reason'],
                    ],
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        // Notify store owner of the rejection
        if ($request->user()->hasRole('branch_manager')) {
            $store->owner?->notify(new PaymentRejectedNotification($jobOrder, $payment, $request->user()));
        }

        // The customer whose payment this was had no way to find out at
        // all otherwise — PaymentRejectedNotification above only ever
        // reaches the owner, and only for a branch_manager's rejection.
        $jobOrder->customer?->notify(new CustomerPaymentRejectedNotification($jobOrder, $payment, $validated['reason']));

        return response()->json([
            'success' => true,
            'message' => 'Payment rejected.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }

    /**
     * Declines a job order before any production has started — a business
     * decision (feasibility, capacity, fabric availability), not a
     * production task, so plain staff can't do this any more than they can
     * touch payments. Only reachable from 'pending': once real work has
     * started, cancel the job instead (see UpdateJobOrderRequest's
     * cancellation_reason flow).
     */
    public function rejectOrder(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        if ($jobOrder->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending order can be rejected — cancel it instead.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $jobOrder->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
        ]);

        $jobOrder->customer?->notify(new JobStatusUpdatedNotification($jobOrder, 'rejected', $request->user()));

        return response()->json([
            'success' => true,
            'message' => 'Job order rejected.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff']),
        ]);
    }

    public function approveOrder(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        if ($jobOrder->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending order can be approved.',
            ], 422);
        }

        $jobOrder->update([
            'status' => 'production',
        ]);

        $jobOrder->customer?->notify(new JobStatusUpdatedNotification($jobOrder, 'approved', $request->user()));

        $this->notifyOwnerOfActivity($request, $store, [
            'type' => 'job_approved',
            'title' => 'Job Order Approved',
            'message' => "{$jobOrder->order_number} ({$jobOrder->customer?->name}) approved and in production.",
            'url' => '/dashboard/jobs/'.$jobOrder->id,
            'extra' => ['job_order_id' => $jobOrder->id, 'order_number' => $jobOrder->order_number],
        ], $jobOrder);

        return response()->json([
            'success' => true,
            'message' => 'Job order approved and moved to production.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff']),
        ]);
    }

    /**
     * Appends a staff-uploaded photo showing real production progress at
     * whatever stage the job is currently in — the "no proof of progress"
     * pain point real store owners named in interview research, distinct from
     * completion_photo_url (one photo, only at the very end) and
     * reference_images (the customer's own inspiration photos attached at
     * intake, not staff-uploaded evidence). Appends, never overwrites — a
     * job accumulates one photo per stage over its lifetime, same "add a
     * ledger entry" shape as payments rather than replacing a single field.
     */
    /**
     * Owner-composed free-form email to the job's customer — separate from
     * the automatic status-change notifications, which fire on their own.
     * The frontend renders its own preview before calling this; there's no
     * separate preview endpoint since nothing here needs server data the
     * owner doesn't already have in front of them.
     */
    public function notifyCustomer(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $customer = $jobOrder->customer;
        if (! $customer || ! $customer->email || str_starts_with($customer->email, 'walkin_')) {
            return response()->json([
                'success' => false,
                'message' => 'This customer has no real email on file to send to.',
            ], 422);
        }

        $customer->notify(new CustomMessageNotification($jobOrder, $validated['subject'], $validated['message']));

        return response()->json([
            'success' => true,
            'message' => 'Message sent to '.$customer->email.'.',
        ]);
    }

    public function addProgressPhoto(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'url' => 'required|string|max:2048',
        ]);

        $photos = $jobOrder->progress_photos ?? [];
        $photos[] = [
            'url' => $validated['url'],
            'stage' => $jobOrder->status,
            'uploaded_at' => now()->toIso8601String(),
        ];
        $jobOrder->forceFill(['progress_photos' => $photos])->save();

        return response()->json([
            'success' => true,
            'message' => 'Progress photo added.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff']),
        ]);
    }

    public function deleteProgressPhoto(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $validated = $request->validate([
            'url' => 'required|string',
        ]);

        $photos = collect($jobOrder->progress_photos ?? [])
            ->filter(fn ($p) => ($p['url'] ?? '') !== $validated['url'])
            ->values()
            ->all();

        $jobOrder->forceFill(['progress_photos' => $photos])->save();

        return response()->json([
            'success' => true,
            'message' => 'Progress photo removed.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff']),
        ]);
    }

    /**
     * Flips completed on/off for one member of a bulk/team order's roster
     * (custom_order_data.team_roster). A 10-jersey job otherwise has a
     * single status for the whole batch — this is the only place per-piece
     * progress ("7 of 10 done") is tracked, separate from the job's overall
     * production stage.
     */
    public function toggleRosterItem(Request $request, Store $store, JobOrder $jobOrder, int $index): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $data = $jobOrder->custom_order_data ?? [];
        // 'roster' is an older key some existing orders still use alongside
        // the current 'team_roster' — same fallback the frontend already
        // reads with (see teamRoster in the Job Detail page).
        $rosterKey = array_key_exists('team_roster', $data) ? 'team_roster' : 'roster';
        $roster = $data[$rosterKey] ?? null;

        if (! is_array($roster) || ! array_key_exists($index, $roster)) {
            return response()->json(['success' => false, 'message' => 'Roster item not found.'], 404);
        }

        $roster[$index]['completed'] = ! ($roster[$index]['completed'] ?? false);
        $data[$rosterKey] = $roster;
        $jobOrder->forceFill(['custom_order_data' => $data])->save();

        return response()->json([
            'success' => true,
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff']),
        ]);
    }

    public function destroy(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['message' => 'Job order not found'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        // Once money has changed hands, deleting the record would silently erase
        // the payment trail. Cancel it instead so the ledger stays intact.
        if ($jobOrder->payments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This job order has recorded payments and cannot be deleted. Cancel it instead to keep the payment history intact.',
            ], 400);
        }

        // Logged before delete() — a soft delete, so the record itself
        // survives, but the Audit Log page had no entry at all for who
        // deleted a job order or when, the same accountability gap
        // discount_applied/payment_rejected/etc. already closed elsewhere.
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'job_order_deleted',
            'model_type' => JobOrder::class,
            'model_id' => $jobOrder->id,
            'payload' => [
                'order_number' => $jobOrder->order_number,
                'status' => $jobOrder->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        $jobOrder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Job order deleted successfully',
        ]);
    }

    public function restore(Request $request, Store $store, int $jobOrderId): JsonResponse
    {
        $jobOrder = JobOrder::onlyTrashed()->where('id', $jobOrderId)->first();

        if (! $jobOrder || $jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Deleted job order not found.'], 404);
        }

        if ($denied = $this->branchAccessDenied($request, $jobOrder)) {
            return $denied;
        }

        $jobOrder->restore();

        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'job_order_restored',
            'model_type' => JobOrder::class,
            'model_id' => $jobOrder->id,
            'payload' => [
                'order_number' => $jobOrder->order_number,
                'status' => $jobOrder->status,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $jobOrder->load(['customer:id,name,suki_tag', 'service', 'assignedStaff:id,name']),
        ]);
    }
}
