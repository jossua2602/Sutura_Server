<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StoreApplication;
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
    private const DISTRICTS = ['Poblacion', 'Talomo', 'Buhangin', 'Agdao', 'Toril', 'Bunawan', 'Calinan', 'Tugbok'];

    /** Upload field on the form => [document column, storage folder]. */
    private const FILES = [
        'landmark' => ['landmark_image', 'landmark_image_path', 'landmarks'],
        'dti' => ['dti_registration', 'dti_registration_path', 'dti'],
        'tin' => ['tin_id', 'tin_id_path', 'tin'],
        'barangay' => ['brgy_clearance', 'brgy_clearance_path', 'barangay'],
        'government-id' => ['government_id', 'government_id_path', 'government-id'],
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
                    'address' => $store->address,
                    'barangay' => $main?->barangay,
                    'district' => $main?->district,
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
            DB::transaction(function () use ($application, $store, $items, $validated, $newPaths, $previous) {
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
                        'address' => $validated['address'],
                        'latitude' => $validated['latitude'],
                        'longitude' => $validated['longitude'],
                    ]);
                    $store->branches()->where('is_main', true)->update([
                        'name' => $validated['store_name'].' — Main',
                        'address' => $validated['address'],
                        'barangay' => $validated['barangay'] ?? null,
                        'district' => in_array($validated['district'] ?? null, self::DISTRICTS, true) ? $validated['district'] : null,
                        'latitude' => $validated['latitude'],
                        'longitude' => $validated['longitude'],
                    ]);
                }

                if (in_array('payment', $items, true)) {
                    $fields['payment_method'] = $validated['payment_method'];
                }
                if (in_array('government-id', $items, true)) {
                    $fields['government_id_type'] = $validated['government_id_type'];
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
                'barangay' => ['nullable', 'string', 'max:100'],
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
