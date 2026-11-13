<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StoreApplication;
use App\Support\Psgc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The owner's side of "Request Changes". They can't sign in before approval,
 * so the private emailed link (?token=) is the only key: it opens this one
 * application, edits only the items the admin ticked, and stops working once
 * used, expired, or the application is decided.
 */
class StoreApplicationUpdateController extends Controller
{

    /** Upload field on the form => [document column, storage folder]. */
    private const FILES = [
        'landmark' => ['landmark_image', 'landmark_image_path', 'landmarks'],
        'dti' => ['dti_registration', 'dti_registration_path', 'dti'],
        'tin' => ['tin_id', 'tin_id_path', 'tin'],
        'barangay' => ['brgy_clearance', 'brgy_clearance_path', 'barangay'],
        'government-id' => ['government_id', 'government_id_path', 'government-id'],
        'selfie' => ['selfie_with_id', 'selfie_with_id_path', 'selfies'],
        'payment' => ['payment_receipt', 'payment_receipt_path', 'receipts'],
    ];

    private static function privateDisk(): string
    {
        return config('filesystems.private_disk', 'local');
    }

    public function show(Request $request): JsonResponse
    {
        $application = StoreApplication::forUpdateToken($request->query('token'));
        if (! $application) {
            return $this->linkGone();
        }

        $store = $application->store;
        $items = collect($application->requestedItems())->pluck('key');
        $main = $store->branches()->where('is_main', true)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'store_name' => $store->name,
                'items' => $application->requestedItems(),
                'note' => $application->change_request_note,
                'requested_at' => $application->change_requested_at,
                'expires_at' => $application->update_token_expires_at,
                // Current values, only for the sections the owner may edit.
                'owner' => $items->contains('owner') ? [
                    'first_name' => $application->first_name,
                    'middle_name' => $application->middle_name,
                    'last_name' => $application->last_name,
                    'suffix' => $application->suffix,
                    'birthday' => $application->birthday?->toDateString(),
                    'contact_number' => $application->contact_number,
                    'email' => $application->user?->contact_email,
                ] : null,
                'shop' => $items->contains('shop') ? [
                    'store_name' => $store->name,
                    // Street line only; the picker holds the rest.
                    'address' => $main?->street ?? $store->address,
                    'barangay' => $main?->barangay,
                    'district' => $main?->district,
                    'province_code' => $main?->province_code,
                    'city_code' => $main?->city_code,
                    'district_code' => $main?->district_code
                        ?? ($main?->city_code === Psgc::DAVAO_CITY && in_array($main?->district, Psgc::DAVAO_DISTRICTS, true) ? 'davao:'.$main->district : null),
                    'barangay_code' => $main?->barangay_code,
                    'latitude' => $store->latitude,
                    'longitude' => $store->longitude,
                ] : null,
                'payment_method' => $application->payment_method,
                'government_id_type' => $application->government_id_type,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $application = StoreApplication::forUpdateToken($request->input('token'));
        if (! $application) {
            return $this->linkGone();
        }

        $items = collect($application->requestedItems())->pluck('key')->all();
        $validated = $request->validate($this->rulesFor($items));
        $store = $application->store;

        // Shop address: the PSGC picks must belong together (same check as the application).
        $location = null;
        if (in_array('shop', $items, true)) {
            $resolved = Psgc::resolve($validated['province_code'], $validated['city_code'], $validated['district_code'] ?? null, $validated['barangay_code']);
            if (! $resolved['ok']) {
                throw \Illuminate\Validation\ValidationException::withMessages($resolved['errors']);
            }
            $location = Psgc::branchColumns($validated, $resolved['names'], trim($validated['address']));
            if (! $location['district'] && $validated['city_code'] === Psgc::DAVAO_CITY && in_array($validated['district'] ?? null, Psgc::DAVAO_DISTRICTS, true)) {
                $location['district'] = $validated['district'];
            }
        }

        // Files go to disk first. The version each one replaces is kept in
        // previous_documents so the admin can compare Before / After; only
        // an older kept version (from an earlier round) is removed, after
        // the commit.
        $newPaths = [];
        $oldPaths = [];
        $previous = $application->previous_documents ?? [];
        $now = now()->toISOString();
        $uploadedAt = fn (string $key) => $previous[$key]['replaced_at'] ?? $application->created_at?->toISOString();
        $keep = function (string $key, ?string $path) use (&$previous, &$oldPaths, $uploadedAt, $now) {
            if (! $path) {
                return;
            }
            if (isset($previous[$key]['path'])) {
                $oldPaths[] = $previous[$key]['path'];
            }
            $previous[$key] = ['path' => $path, 'uploaded_at' => $uploadedAt($key), 'replaced_at' => $now];
        };

        foreach (self::FILES as $item => [$field, $column, $folder]) {
            if (in_array($item, $items, true)) {
                $newPaths[$column] = $request->file($field)->store("store-applications/{$folder}", self::privateDisk());
                $keep(array_search($column, StoreApplication::DOCUMENTS, true), $application->{$column});
            }
        }
        if (in_array('permits', $items, true)) {
            $newPaths['business_permit_paths'] = collect($request->file('business_permits', []))
                ->map(fn (UploadedFile $f) => $f->store('store-applications/permits', self::privateDisk()))
                ->values()->all();
            // Permit N is compared with the old permit N; old permits past
            // the new count have nothing to pair with, so they go.
            foreach ($application->business_permit_paths ?? [] as $i => $path) {
                if ($i < count($newPaths['business_permit_paths'])) {
                    $keep('permit-'.$i, $path);
                } else {
                    $oldPaths[] = $path;
                }
            }
        }

        try {
            DB::transaction(function () use ($application, $store, $items, $validated, $newPaths, $previous, $location) {
                $fields = $newPaths + ['previous_documents' => $previous ?: null];

                if (in_array('owner', $items, true)) {
                    $fields += collect($validated)->only(['first_name', 'middle_name', 'last_name', 'suffix', 'birthday', 'contact_number'])->all();
                    $name = trim(preg_replace('/\s+/', ' ', "{$validated['first_name']} ".($validated['middle_name'] ?? '')." {$validated['last_name']} ".($validated['suffix'] ?? '')));
                    $application->user?->forceFill([
                        'name' => $name,
                        'contact_email' => $validated['email'],
                        'phone' => $validated['contact_number'],
                    ])->save();
                    $store->update(['phone' => $validated['contact_number'], 'email' => $validated['email']]);
                }

                if (in_array('shop', $items, true)) {
                    $store->update([
                        'name' => $validated['store_name'],
                        'address' => $location['address'],
                        'city' => $location['city'],
                        'province' => $location['province'],
                        'latitude' => $validated['latitude'],
                        'longitude' => $validated['longitude'],
                    ]);
                    $store->branches()->where('is_main', true)->update([
                        'name' => $validated['store_name'].' — Main',
                        ...$location,
                        'latitude' => $validated['latitude'],
                        'longitude' => $validated['longitude'],
                    ]);
                }

                if (in_array('payment', $items, true)) {
                    $fields['payment_method'] = $validated['payment_method'];
                    // Admin → Payments shows the new receipt on the application's payment.
                    $payment = \App\Models\SubscriptionUpgradeRequest::where('store_application_id', $application->id)->where('status', 'pending')->first();
                    if ($payment && isset($newPaths['payment_receipt_path'])) {
                        $payment->update(['payment_receipt_path' => $newPaths['payment_receipt_path']]);
                        $payment->log('receipt_replaced', $application->user_id, 'New receipt sent through Request Changes.');
                    }
                }
                if (in_array('government-id', $items, true)) {
                    $fields['government_id_type'] = $validated['government_id_type'];
                }
                if (in_array('selfie', $items, true)) {
                    // A new selfie gets its own face check; the old result no longer applies.
                    $fields['face_match'] = $validated['face_match'] ?? null;
                    $fields['face_match_distance'] = isset($validated['face_match']) ? ($validated['face_match_distance'] ?? null) : null;
                }

                $history = $application->change_history ?? [];
                $history[] = [
                    'items' => $application->change_request_items,
                    'note' => $application->change_request_note,
                    'requested_at' => $application->change_requested_at?->toISOString(),
                    'requested_by' => $application->changeRequester?->name,
                    'resubmitted_at' => now()->toISOString(),
                ];

                $application->update($fields + [
                    'review_state' => 'resubmitted',
                    'resubmitted_at' => now(),
                    'update_token_hash' => null,       // one use
                    'update_token_expires_at' => null,
                    'change_history' => $history,
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk(self::privateDisk())->delete(collect($newPaths)->flatten()->filter()->all());
            throw $e;
        }

        Storage::disk(self::privateDisk())->delete(array_values(array_filter($oldPaths)));

        return response()->json([
            'success' => true,
            'message' => 'Thanks! Your updates were sent to SUTURA for review. We will email you once your shop is approved.',
        ]);
    }

    /** Only the ticked items are accepted, and each of them is required. */
    private function rulesFor(array $items): array
    {
        $doc = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];
        $image = ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
        $rules = ['token' => ['required', 'string']];

        if (in_array('owner', $items, true)) {
            $rules += [
                'first_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'suffix' => ['nullable', 'string', 'max:20'],
                'birthday' => ['required', 'date', 'before:-18 years'],
                'email' => ['required', 'string', 'email', 'max:191'],
                'contact_number' => ['required', 'string', 'max:20'],
            ];
        }
        if (in_array('shop', $items, true)) {
            $rules += [
                'store_name' => ['required', 'string', 'max:191'],
                'address' => ['required', 'string', 'max:255'],
                'province_code' => ['required', 'string', 'max:10'],
                'city_code' => ['required', 'string', 'max:10'],
                'district_code' => ['nullable', 'string', 'max:20'],
                'barangay_code' => ['required', 'string', 'max:10'],
                'district' => ['nullable', 'string', 'max:100'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
            ];
        }
        if (in_array('payment', $items, true)) {
            $rules += ['payment_method' => ['required', Rule::in(['gcash'])], 'payment_receipt' => $image];
        }
        if (in_array('landmark', $items, true)) {
            $rules['landmark_image'] = $image;
        }
        if (in_array('selfie', $items, true)) {
            $rules += [
                'selfie_with_id' => $image,
                'face_match' => ['nullable', Rule::in(StoreApplication::FACE_MATCH_RESULTS)],
                'face_match_distance' => ['nullable', 'numeric', 'between:0,2'],
            ];
        }
        foreach (['dti' => 'dti_registration', 'tin' => 'tin_id', 'barangay' => 'brgy_clearance'] as $item => $field) {
            if (in_array($item, $items, true)) {
                $rules[$field] = $doc;
            }
        }
        if (in_array('government-id', $items, true)) {
            $rules += ['government_id' => $doc, 'government_id_type' => ['required', 'string', 'max:100']];
        }
        if (in_array('permits', $items, true)) {
            $rules += ['business_permits' => ['required', 'array', 'min:1', 'max:5'], 'business_permits.*' => $doc];
        }

        return $rules;
    }

    private function linkGone(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This update link has expired or was already used. If SUTURA still needs changes, ask them to send a new link.',
        ], 410);
    }
}
