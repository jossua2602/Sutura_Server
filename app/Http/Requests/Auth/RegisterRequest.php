<?php

namespace App\Http\Requests\Auth;

use App\Support\ShopLogin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Trim and collapse spaces in the name before validating. */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim(preg_replace('/\s+/u', ' ', (string) $this->input('name')))]);
        }
    }

    public function messages(): array
    {
        return [
            'email.not_regex' => 'Shop logins can\'t be used for a customer account. Sign up with your personal email.',
            'name.regex' => 'Names can only contain letters, spaces, hyphens, apostrophes, and periods.',
        ];
    }

    public function rules(): array
    {
        return [
            // Letters (incl. ñ, á, é…), spaces, hyphens, apostrophes, periods — no
            // digits or symbols, and at least one letter. Same rule as the shop application.
            'name' => ['required', 'string', 'max:191', 'regex:'.StoreApplicationRequest::NAME_PATTERN, 'regex:/\p{L}/u'],
            // No `unique:users` here — a guest-booking "shadow" account (no
            // real password yet) may already own this email, and the
            // controller lets that case claim the account instead of
            // blocking on a false-positive duplicate.
            'email' => ['required', 'string', 'email', 'max:191', 'not_regex:/@'.preg_quote(ShopLogin::domain(), '/').'$/i'],
            // Password::defaults() (AppServiceProvider) — min 8, mixed
            // case, a number, and a symbol. Matches ProfileController's
            // password-change rule and the frontend's live checklist.
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:20'],
            // Was `exists:roles,name`, which let anyone POST role=admin and
            // self-register a System Admin account. Self-signup is customers
            // only now: store owners go through /store-applications (admin
            // reviewed), staff are created by their owner, admins are seeded.
            'role' => ['required', 'string', Rule::in(['customer'])],
        ];
    }
}
