<?php

namespace App\Http\Requests\Auth;

use App\Support\CanonicalTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Public "Open a shop" application — creates a pending store and the
 * verification packet. No password here: the System Admin issues the shop
 * login on approval and it's emailed to `email` (the owner's own inbox).
 * Field set ported from the sutura2 prototype's ShopRegistrationController, minus
 * `subscription_price`: the quote is computed from the plan row server-side
 * instead of trusting whatever number the client posts.
 */
class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $doc = ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];

        return [
            // Owner
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'birthday' => ['required', 'date', 'before:-18 years'],
            // The owner's real inbox — where the issued login is sent. Not a
            // sign-in identifier, so it may match an existing customer account.
            'email' => ['required', 'string', 'email', 'max:191'],
            'contact_number' => ['required', 'string', 'max:20'],

            // Store
            'store_name' => ['required', 'string', 'max:191'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'barangay' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // At least one service: picked from the list, typed in, or both.
            'specializations' => ['required_without:custom_services', 'array'],
            // New shops pick from the current list only (no retired tags).
            'specializations.*' => ['string', Rule::in(CanonicalTaxonomy::storeSpecializations())],
            // "Add Other Service": short free-text services not in the list.
            'custom_services' => ['required_without:specializations', 'nullable', 'array', 'max:5'],
            'custom_services.*' => ['string', 'min:2', 'max:40', 'distinct:ignore_case'],
            // "Branches (Optional)": other locations, display only (stores.declared_branches),
            // allowed on any plan. Setting up a real branch stays behind the plan's limit.
            'branches' => ['nullable', 'array', 'max:10'],
            'branches.*.name' => ['required', 'string', 'max:191', 'distinct:ignore_case'],
            'branches.*.address' => ['required', 'string', 'max:255'],

            // Documents
            'landmark_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'dti_registration' => ['required', ...$doc],
            'tin_id' => ['required', ...$doc],
            'brgy_clearance' => ['required', ...$doc],
            'government_id' => ['required', ...$doc],
            'government_id_type' => ['required', 'string', 'max:100'],
            'business_permits' => ['nullable', 'array', 'max:5'],
            'business_permits.*' => $doc,

            // Plan + payment
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            // Plan payments are GCash only; bank transfer was removed.
            'payment_method' => ['required', Rule::in(['gcash'])],
            'payment_receipt' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'birthday.before' => 'The shop owner must be at least 18 years old.',
        ];
    }
}
