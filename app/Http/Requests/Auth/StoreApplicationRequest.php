<?php

namespace App\Http\Requests\Auth;

use App\Support\CanonicalTaxonomy;
use App\Support\ShopContactAvailability;
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
    /** Letters (any language), spaces, hyphens, apostrophes, periods. */
    public const NAME_PATTERN = "/^[\\p{L}\\s'.\\-]+$/u";

    /** Trim names before validating, and send empty optional ones as null. */
    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['first_name', 'middle_name', 'last_name', 'suffix'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $clean[$field] = $value === '' ? null : $value;
            }
        }
        $this->merge($clean);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $doc = ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];

        return [
            // Owner
            // Letters (incl. ñ, á, é…), spaces, hyphens, apostrophes and periods only.
            // First/last must also contain a letter; middle name and suffix are optional.
            'first_name' => ['required', 'string', 'max:100', 'regex:'.self::NAME_PATTERN, 'regex:/\p{L}/u'],
            'middle_name' => ['nullable', 'string', 'max:100', 'regex:'.self::NAME_PATTERN],
            'last_name' => ['required', 'string', 'max:100', 'regex:'.self::NAME_PATTERN, 'regex:/\p{L}/u'],
            'suffix' => ['nullable', 'string', 'max:20', 'regex:'.self::NAME_PATTERN],
            'birthday' => ['required', 'date', 'before:-18 years'],
            // The owner's real inbox — where the issued login is sent. Not a
            // sign-in identifier, so it may match an existing customer account.
            // ...but not one another shop already registered with (rejected ones don't count).
            'email' => ['required', 'string', 'email', 'max:191', function ($attr, $value, $fail) {
                if (ShopContactAvailability::emailTaken((string) $value)) {
                    $fail('This email is already registered to another shop.');
                }
            }],
            // Philippine mobile: 11 digits starting with 09 (e.g. 09663946888).
            'contact_number' => ['required', 'string', 'regex:/^09\d{9}$/', function ($attr, $value, $fail) {
                if (ShopContactAvailability::phoneTaken((string) $value)) {
                    $fail('This contact number is already registered to another shop.');
                }
            }],

            // Store
            'store_name' => ['required', 'string', 'max:191'],
            // Street line only (unit, building, street); the rest is picked from PSGC.
            'address' => ['required', 'string', 'max:255'],
            // PSGC picks — only listed codes, checked as a chain in after().
            'province_code' => ['required', 'string', 'max:10'],
            'city_code' => ['required', 'string', 'max:10'],
            'district_code' => ['nullable', 'string', 'max:20'],
            'barangay_code' => ['required', 'string', 'max:10'],
            // The map pin's district (Davao), used only when no district was picked.
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
            'branches.*.province_code' => ['required', 'string', 'max:10'],
            'branches.*.city_code' => ['required', 'string', 'max:10'],
            'branches.*.district_code' => ['nullable', 'string', 'max:20'],
            'branches.*.barangay_code' => ['required', 'string', 'max:10'],

            // Documents
            'landmark_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'dti_registration' => ['required', ...$doc],
            // Recommended, not required (many small tailors aren't BIR-registered yet).
            'tin_id' => ['nullable', ...$doc],
            'brgy_clearance' => ['required', ...$doc],
            'government_id' => ['required', ...$doc],
            'government_id_type' => ['required', 'string', 'max:100'],
            // Live selfie holding the ID (camera capture, checked by the review team).
            'selfie_with_id' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'business_permits' => ['nullable', 'array', 'max:5'],
            'business_permits.*' => $doc,

            // Plan + payment
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            // Plan payments are GCash only; bank transfer was removed.
            'payment_method' => ['required', Rule::in(['gcash'])],
            'payment_receipt' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            // Data Privacy Act (RA 10173) consent (Documents step) and the
            // terms agreement (last step). Both must be ticked.
            'privacy_consent' => ['accepted'],
            'terms_accepted' => ['accepted'],
        ];
    }

    /** Resolved PSGC names, keyed 'shop' and 'branch.<i>' (filled in after()). */
    public array $resolvedAddresses = [];

    /**
     * The province / city / district / barangay picks must exist and belong
     * together (a barangay of another city is refused), for the shop and for
     * each listed branch.
     */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator) {
            // One message per field: skip a field that already failed its basic rule.
            $add = function (string $field, string $message) use ($validator) {
                if (! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, $message);
                }
            };

            // Non-strings (e.g. an array posted on purpose) already failed 'string'; treat them as empty.
            $s = fn ($v) => is_string($v) ? $v : null;
            $shop = \App\Support\Psgc::resolve($s($this->province_code), $s($this->city_code), $s($this->district_code), $s($this->barangay_code));
            foreach ($shop['errors'] as $field => $message) {
                $add($field, $message);
            }
            $this->resolvedAddresses['shop'] = $shop['names'];

            $branches = $this->input('branches', []);
            foreach (is_array($branches) ? $branches : [] as $i => $branch) {
                $branch = is_array($branch) ? $branch : [];
                $r = \App\Support\Psgc::resolve($s($branch['province_code'] ?? null), $s($branch['city_code'] ?? null), $s($branch['district_code'] ?? null), $s($branch['barangay_code'] ?? null));
                foreach ($r['errors'] as $field => $message) {
                    $add("branches.{$i}.{$field}", 'Branch '.($i + 1).': '.$message);
                }
                $this->resolvedAddresses["branch.{$i}"] = $r['names'];
            }
        }];
    }

    public function messages(): array
    {
        return [
            'birthday.before' => 'The shop owner must be at least 18 years old.',
            'province_code.required' => 'Choose your province.',
            'city_code.required' => 'Choose your city or municipality.',
            'barangay_code.required' => 'Choose your barangay.',
            'branches.*.province_code.required' => 'Choose the branch’s province.',
            'branches.*.city_code.required' => 'Choose the branch’s city or municipality.',
            'branches.*.barangay_code.required' => 'Choose the branch’s barangay.',
            'contact_number.regex' =>'Enter an 11-digit mobile number starting with 09, e.g. 09663946888.',
            'first_name.regex' => 'Names can only contain letters, spaces, hyphens, apostrophes, and periods.',
            'middle_name.regex' => 'Names can only contain letters, spaces, hyphens, apostrophes, and periods.',
            'last_name.regex' => 'Names can only contain letters, spaces, hyphens, apostrophes, and periods.',
            'suffix.regex' => 'Names can only contain letters, spaces, hyphens, apostrophes, and periods.',
            'selfie_with_id.required' => 'Take a selfie holding your government ID.',
            'selfie_with_id.image' => 'The selfie must be a photo (JPG, PNG, or WEBP).',
            'selfie_with_id.max' => 'The selfie must be 5 MB or smaller. Please retake it.',
            'privacy_consent.accepted' => 'Please agree to the privacy consent on the Documents step.',
            'terms_accepted.accepted' => 'Please agree to the Terms of Service and confirm your information is true.',
        ];
    }
}
