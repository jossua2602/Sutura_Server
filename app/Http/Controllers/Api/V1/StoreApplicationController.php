<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreApplicationRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreApplication;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Psgc;
use App\Support\ShopLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public side of shop onboarding (ported from sutura2's shop registration).
 *
 * Creates the owner record with a placeholder shop-domain login and an
 * unusable random password, so nobody can sign in yet. The store stays
 * status=pending (hidden from every public query, which already filters on
 * status=approved) until a System Admin approves it and issues the real
 * login + a temporary password (Admin\StoreApplicationController::approve),
 * emailed to the owner's contact_email. Unlike sutura2, that temporary
 * password is random — never lastname+birthday — and must be changed on
 * first sign-in.
 */
class StoreApplicationController extends Controller
{
    private static function privateDisk(): string
    {
        return config('filesystems.private_disk', 'local');
    }


    public function plans(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => SubscriptionPlan::where('is_active', true)
                ->orderBy('price_monthly')
                ->get(['id', 'name', 'slug', 'description', 'price_monthly', 'price_yearly', 'max_staff', 'max_branches', 'features']),
        ]);
    }

    /**
     * Live check for the "Open a shop" form: is this email / contact number
     * already used by another shop? Only answers for well-formed values, and
     * the route is rate-limited so it can't be used to probe in bulk.
     */
    public function checkAvailability(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'contact_number' => ['nullable', 'string', 'regex:/^09\d{9}$/'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'email' => isset($validated['email'])
                    ? (\App\Support\ShopContactAvailability::emailTaken($validated['email']) ? 'taken' : 'available')
                    : null,
                'contact_number' => isset($validated['contact_number'])
                    ? (\App\Support\ShopContactAvailability::phoneTaken($validated['contact_number']) ? 'taken' : 'available')
                    : null,
            ],
        ]);
    }

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($request->plan_id);
        $paths = $this->storeDocuments($request);

        try {
            $this->createApplication($request, $paths, $plan);
        } catch (\Throwable $e) {
            // Don't leave orphaned government IDs on disk for a rolled-back row.
            Storage::disk(self::privateDisk())->delete(collect($paths)->flatten()->filter()->all());
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Application submitted. We will email your shop login once it is approved.',
            'data' => ['contact_email' => $request->email],
        ], 201);
    }

    private function createApplication(StoreApplicationRequest $request, array $paths, SubscriptionPlan $plan): void
    {
        DB::transaction(function () use ($request, $paths, $plan) {
            // Structured address (PSGC) → "Street, Barangay, City, District, Province".
            $names = $request->resolvedAddresses['shop'];
            $codes = $request->only(['province_code', 'city_code', 'district_code', 'barangay_code']);
            $location = Psgc::branchColumns($codes, $names, trim($request->address));
            // Davao City: no district picked → keep the map pin's, if it's one of the filter districts.
            if (! $location['district'] && $request->city_code === Psgc::DAVAO_CITY && in_array($request->district, Psgc::DAVAO_DISTRICTS, true)) {
                $location['district'] = $request->district;
            }

            $name = trim(preg_replace('/\s+/', ' ', "{$request->first_name} {$request->middle_name} {$request->last_name} {$request->suffix}"));
            $user = User::create([
                'name' => $name,
                'email' => ShopLogin::suggest($request->store_name),
                'contact_email' => $request->email,
                'phone' => $request->contact_number,
                // Never communicated to anyone — the account is unusable
                // until approval issues a real temporary password.
                'password' => Hash::make(Str::random(64)),
            ]);

            $role = Role::where('name', 'store_owner')->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);

            $store = Store::create([
                'owner_id' => $user->id,
                'name' => $request->store_name,
                'slug' => Str::slug($request->store_name).'-'.uniqid(),
                'address' => $location['address'],
                'city' => $location['city'],
                'province' => $location['province'],
                'phone' => $request->contact_number,
                'email' => $request->email,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'specializations' => array_values(array_unique($request->specializations ?? [])),
                'custom_services' => collect($request->custom_services ?? [])
                    ->map(fn ($s) => trim(preg_replace('/\s+/', ' ', (string) $s)))
                    ->filter()
                    ->unique(fn ($s) => mb_strtolower($s))
                    ->values()
                    ->all() ?: null,
                // "Branches (Optional)": display only, on any plan. Not store_branches
                // rows — setting up a real branch (pin, booking) stays Premium-only.
                'declared_branches' => collect($request->branches ?? [])
                    ->map(function ($b, $i) use ($request) {
                        $columns = Psgc::branchColumns($b, $request->resolvedAddresses["branch.{$i}"], trim($b['address']));

                        return ['name' => trim($b['name'])] + collect($columns)
                            ->only(['address', 'street', 'province', 'province_code', 'city', 'city_code', 'district', 'district_code', 'barangay', 'barangay_code'])
                            ->all();
                    })
                    ->values()
                    ->all() ?: null,
                'status' => 'pending',
            ]);

            $store->branches()->create([
                'name' => $request->store_name.' — Main',
                'slug' => Str::slug($request->store_name).'-'.uniqid(),
                ...$location,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'contact_number' => $request->contact_number,
                'is_main' => true,
                'status' => 'active',
                'verification_status' => 'pending',   // verified when the admin approves the application
            ]);

            $application = StoreApplication::create([
                'user_id' => $user->id,
                'store_id' => $store->id,
                'first_name' => $request->first_name,
                'middle_name' => $request->middle_name,
                'last_name' => $request->last_name,
                'suffix' => $request->suffix,
                'birthday' => $request->birthday,
                'contact_number' => $request->contact_number,
                'requested_plan_id' => $plan->id,
                'billing_cycle' => $request->billing_cycle,
                'quoted_price' => $request->billing_cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly,
                'payment_method' => $request->payment_method,
                'government_id_type' => $request->government_id_type,
                // Proof of what the owner agreed to, and when.
                'privacy_consent_at' => now(),
                'terms_accepted_at' => now(),
                'consent_version' => StoreApplication::CONSENT_VERSION,
                ...$paths,
            ]);

            // The plan payment shows in Admin → Payments (decided with the application).
            $payment = \App\Models\SubscriptionUpgradeRequest::create([
                'store_id' => $store->id,
                'type' => 'new_shop',
                'store_application_id' => $application->id,
                'requested_by' => $user->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $request->billing_cycle === 'yearly' ? 'yearly' : 'monthly',
                'quoted_price' => $application->quoted_price,
                'payment_method' => $request->payment_method,
                'payment_receipt_path' => $paths['payment_receipt_path'],
            ]);
            $payment->log('submitted', $user->id, 'Sent with the shop application.');
        });
    }

    /** @return array<string, mixed> column => stored path */
    private function storeDocuments(StoreApplicationRequest $request): array
    {
        $put = fn (UploadedFile $file, string $folder) => $file->store("store-applications/{$folder}", self::privateDisk());

        return [
            'landmark_image_path' => $put($request->file('landmark_image'), 'landmarks'),
            'dti_registration_path' => $put($request->file('dti_registration'), 'dti'),
            'tin_id_path' => $request->hasFile('tin_id') ? $put($request->file('tin_id'), 'tin') : null,
            'brgy_clearance_path' => $put($request->file('brgy_clearance'), 'barangay'),
            'government_id_path' => $put($request->file('government_id'), 'government-id'),
            'selfie_with_id_path' => $put($request->file('selfie_with_id'), 'selfies'),
            'payment_receipt_path' => $put($request->file('payment_receipt'), 'receipts'),
            'business_permit_paths' => collect($request->file('business_permits', []))
                ->map(fn (UploadedFile $file) => $put($file, 'permits'))
                ->values()
                ->all(),
        ];
    }
}
