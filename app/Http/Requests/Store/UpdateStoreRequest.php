<?php

namespace App\Http\Requests\Store;

use App\Support\CanonicalTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $this->user()->id === $store->owner_id;
    }

    public function rules(): array
    {
        return [
            ...\App\Support\OrderRequirements::storeRules(),
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'logo_path' => ['nullable', 'string'],
            'banner_path' => ['nullable', 'string'],
            // Never actually validated (or persisted — see the
            // add_gallery_images_to_stores_table migration) despite a full
            // upload UI existing for it. 'billing' page in this app markets
            // gallery photos as "unlimited" (no plan differentiates on it),
            // but unbounded is still a real payload-size/abuse vector — 100
            // is generous for a store's real portfolio without being a
            // meaningful product limit.
            'gallery_images' => ['nullable', 'array', 'max:100'],
            'gallery_images.*' => ['string', 'max:1000'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'business_type' => ['nullable', 'string', 'max:50'],
            'booking_policy' => ['nullable', 'string'],
            'booking_questions' => ['nullable', 'array'],
            'max_appointments_per_day' => ['nullable', 'integer', 'min:1'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'social_links' => ['nullable', 'array', 'max:12', new \App\Rules\SafeLink],
            'operating_hours' => ['nullable', 'array'],
            // How many fitting appointments a job order gets before the store
            // starts charging an extra fitting fee — see JobOrderController's
            // fitting-count enforcement. Not a rental concept.
            'fitting_fee' => ['nullable', 'numeric'],
            'fitting_limit' => ['nullable', 'integer', 'min:1'],
            'fitting_limit_policy' => ['sometimes', 'in:fee,block'],
            // Days a customer has to come in once the order is Ready for Fitting.
            'fitting_visit_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            // Ready-for-Fitting message to the customer; {days} and {date} are filled in.
            'fitting_message' => ['nullable', 'string', 'max:300'],
            'repair_requires_downpayment' => ['sometimes', 'boolean'],
            'specializations' => ['nullable', 'array'],
            // Men/Women/Kids departments + Services categories — the same axes
            // the header nav and /search use (see CanonicalTaxonomy).
            'specializations.*' => ['string', Rule::in(CanonicalTaxonomy::selectableStoreSpecializations())],
            // is_featured is deliberately NOT owner-writable here — it's a
            // Premium-plan perk the system keeps in sync automatically
            // (see SubscriptionController::subscribe() and
            // ExpireSubscriptions), not a toggle the owner can flip on
            // their own regardless of plan.
            'is_hidden' => ['sometimes', 'boolean'],
            // Where customers should actually send a GCash/bank payment —
            // informational only, the system still never moves money itself.
            'gcash_number' => ['nullable', 'string', 'max:20'],
            'gcash_account_name' => ['nullable', 'string', 'max:191'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_account_name' => ['nullable', 'string', 'max:191'],
            'gcash_qr_path' => ['nullable', 'string'],
            'bank_qr_path' => ['nullable', 'string'],
        ];
    }
}
