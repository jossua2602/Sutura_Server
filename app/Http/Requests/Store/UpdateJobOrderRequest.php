<?php

namespace App\Http\Requests\Store;

use App\Models\JobOrder;
use App\Support\RosterMeasurementRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $store = $this->route('store');

        return [
            'intake_channel' => ['sometimes', 'in:walk_in,online'],
            // Store pickup only — the approved thesis explicitly excludes
            // logistics/courier/delivery management from the system's scope.
            'fulfillment_type' => ['sometimes', 'in:pickup'],
            'assigned_staff_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id'),
            ],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('users', 'id'),
            ],
            'measurement_id' => [
                'nullable', 'integer',
                Rule::exists('measurements', 'id')->where('store_id', $store?->id),
            ],
            // balance/payment_status are intentionally NOT editable here — they must
            // only move through JobOrderController@pay, which recomputes the balance
            // from the current DB value inside one request instead of trusting
            // whatever stale figure the client happened to have loaded. Accepting
            // them here would let a stale tab silently overwrite a payment another
            // user just recorded (or let anyone mark a job "paid" with no ledger entry).
            'status' => ['sometimes', Rule::in(JobOrder::STATUSES)],
            'cancellation_reason' => ['nullable', 'string', Rule::in(JobOrder::CANCELLATION_REASONS), 'required_if:status,cancelled'],
            'due_date' => ['nullable', 'date'],
            // Time-of-day ETA, additive to due_date — see docs/REPAIR-WORKFLOW.md §6.
            'estimated_ready_at' => ['nullable', 'date'],
            // Meaningful only when material_source = customer_supplied; the
            // request layer doesn't enforce that pairing (a caller could set it
            // without material_source being 'customer_supplied' yet in the same
            // request) since material_source is itself independently editable
            // below — same "don't over-constrain a field that's just a fact
            // record" reasoning as cancellation_reason/hold_reason nearby.
            'customer_material_status' => ['nullable', Rule::in(JobOrder::CUSTOMER_MATERIAL_STATUSES)],
            'notes' => ['nullable', 'string'],
            'custom_order_data' => ['nullable', 'array'],
            'custom_order_data.*' => ['nullable'],
            'custom_order_data.team_name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.organization' => ['nullable', 'string', 'max:255'],
            'custom_order_data.order_purpose' => ['nullable', 'string', 'max:255'],
            'custom_order_data.garment_type' => ['nullable', 'string', 'max:100'],
            'custom_order_data.garment_component' => ['nullable', 'string', 'in:top_only,bottom_only,full_set,custom_set'],
            'custom_order_data.fabric_preference' => ['nullable', 'string', 'max:100'],
            'custom_order_data.colorway_notes' => ['nullable', 'string', 'max:500'],
            'custom_order_data.neckline_style' => ['nullable', 'string', 'max:100'],
            'custom_order_data.shorts_cut' => ['nullable', 'string', 'max:100'],
            'custom_order_data.artwork_status' => ['nullable', 'string', 'in:not_yet_provided,submitted,for_review,approved,revision_requested'],
            'custom_order_data.total_quantity' => ['nullable', 'integer', 'min:1'],
            'custom_order_data.size_breakdown' => ['nullable', 'array'],
            'custom_order_data.has_personalization' => ['nullable', 'boolean'],
            'custom_order_data.personalization_config' => ['nullable', 'array'],
            'custom_order_data.team_roster' => ['nullable', 'array'],
            'custom_order_data.team_roster.*.name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster.*.print_name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster.*.number' => ['nullable', 'string', 'max:100'],
            'custom_order_data.team_roster.*.position' => ['nullable', 'string', 'max:100'],
            'custom_order_data.team_roster.*.role' => ['nullable', 'string', 'max:100'],
            'custom_order_data.team_roster.*.size' => ['nullable', 'string', 'max:50'],
            'custom_order_data.team_roster.*.top_size' => ['nullable', 'string', 'max:50'],
            'custom_order_data.team_roster.*.bottom_size' => ['nullable', 'string', 'max:50'],
            'custom_order_data.team_roster.*.remarks' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster.*.completed' => ['nullable', 'boolean'],
            // Each person's body measurements / "measure at shop" flag (walk-in bulk orders).
            ...RosterMeasurementRules::rules(),
            'store_branch_id' => [
                'nullable', 'integer',
                Rule::exists('store_branches', 'id')->where('store_id', $store?->id),
            ],
            'is_rush' => ['sometimes', 'boolean'],
            'rush_fee' => ['sometimes', 'numeric', 'min:0'],
            'catalog_item_id' => [
                'nullable', 'integer',
                Rule::exists('catalog_items', 'id')->where('store_id', $store?->id),
            ],
            'completion_photo_url' => ['nullable', 'string', 'max:500'],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            // Lets a job with no appointment_id (e.g. a walk-in custom order) still
            // get a reference photo/link attached after the fact, not just at creation.
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link' => ['nullable', 'string', 'max:500', new \App\Rules\SafeLink],
            'material_source' => ['nullable', Rule::in(JobOrder::MATERIAL_SOURCES)],
            'garment_category' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Support\GarmentCategory::all())],
            'hold_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
