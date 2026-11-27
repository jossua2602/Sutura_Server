<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\Measurement;
use App\Models\Store;

class JobMeasurementSync
{
    /**
     * Synchronize body measurements and sheets from a job order's custom_order_data
     * into the customer's measurement profiles in the database and link measurement_id.
     */
    public static function syncForJob(Store $store, JobOrder $jobOrder): ?Measurement
    {
        $customData = $jobOrder->custom_order_data ?? [];
        $individual = $customData['individual_measurements'] ?? [];

        $metrics = [];
        if (is_array($individual)) {
            foreach ($individual as $k => $v) {
                if ($k !== 'unit' && $v !== null && trim((string) $v) !== '') {
                    $metrics[$k] = trim((string) $v);
                }
            }
        }

        $sheets = [];
        if (! empty($customData['measurement_sheets']) && is_array($customData['measurement_sheets'])) {
            $sheets = array_values(array_filter($customData['measurement_sheets'], fn ($u) => ! empty($u)));
        }

        // If no typed metrics and no sheet photos, there is nothing to store as a profile
        if (empty($metrics) && empty($sheets)) {
            return null;
        }

        // A profile also keeps its unit and what it was made for. Those aren't
        // measurements, so they never count as a change; a new version keeps them.
        // `==` (not `===`): the same values in another order are not a change either.
        $unit = ($individual['unit'] ?? null) === 'cm' ? 'cm' : 'in';
        $sameAs = fn (Measurement $m) => array_diff_key($m->metrics ?? [], ['unit' => 1, 'garment' => 1]) == $metrics
            && ($m->photo_urls ?? []) == $sheets;
        $withExtras = fn (?Measurement $m) => $metrics + ['unit' => $unit]
            + (! empty($m?->metrics['garment']) ? ['garment' => $m->metrics['garment']] : []);

        $customerId = $jobOrder->customer_id;
        if (! $customerId) {
            return null;
        }

        $garment = ! empty($jobOrder->garment_category)
            ? ucfirst(str_replace('_', ' ', $jobOrder->garment_category))
            : (! empty($jobOrder->service?->name) ? ucfirst($jobOrder->service->name) : 'Standard');
        $profileName = "{$garment} Profile";

        // 1. Check if job already has a valid linked measurement
        if ($jobOrder->measurement_id) {
            $existing = Measurement::where('store_id', $store->id)
                ->where('customer_id', $customerId)
                ->where('id', $jobOrder->measurement_id)
                ->first();

            if ($existing) {
                // If metrics and sheets are identical, keep it as is
                if ($sameAs($existing)) {
                    return $existing;
                }

                // If superseded_at is null, supersede and create the next version
                if ($existing->superseded_at === null) {
                    $existing->update(['superseded_at' => now()]);
                    $next = $store->measurements()->create([
                        'customer_id' => $customerId,
                        'source' => 'store_owner',
                        'profile_name' => $existing->profile_name,
                        'version' => $existing->version + 1,
                        'metrics' => $withExtras($existing),
                        'photo_urls' => $sheets,
                        'notes' => 'Updated from Job Order ' . $jobOrder->order_number,
                        'status' => 'finalized',
                    ]);
                    $jobOrder->update(['measurement_id' => $next->id]);
                    return $next;
                }
            }
        }

        // 2. Look for an existing active profile for this customer and profile name
        $activeProfile = Measurement::where('store_id', $store->id)
            ->where('customer_id', $customerId)
            ->where('profile_name', $profileName)
            ->whereNull('superseded_at')
            ->first();

        if ($activeProfile) {
            if ($sameAs($activeProfile)) {
                if ($jobOrder->measurement_id !== $activeProfile->id) {
                    $jobOrder->update(['measurement_id' => $activeProfile->id]);
                }
                return $activeProfile;
            }

            // Metrics or photos changed — supersede current version and create next version
            $activeProfile->update(['superseded_at' => now()]);
            $next = $store->measurements()->create([
                'customer_id' => $customerId,
                'source' => 'store_owner',
                'profile_name' => $profileName,
                'version' => $activeProfile->version + 1,
                'metrics' => $withExtras($activeProfile),
                'photo_urls' => $sheets,
                'notes' => 'Recorded from Job Order ' . $jobOrder->order_number,
                'status' => 'finalized',
            ]);
            $jobOrder->update(['measurement_id' => $next->id]);
            return $next;
        }

        // 3. Create brand-new profile (Version 1)
        $newProfile = $store->measurements()->create([
            'customer_id' => $customerId,
            'source' => 'store_owner',
            'profile_name' => $profileName,
            'version' => 1,
            'metrics' => $withExtras(null),
            'photo_urls' => $sheets,
            'notes' => 'Auto-recorded from Job Order ' . $jobOrder->order_number,
            'status' => 'finalized',
        ]);

        $jobOrder->update(['measurement_id' => $newProfile->id]);
        return $newProfile;
    }

    /**
     * Auto-sync any unlinked job orders for a customer into their measurement profiles.
     */
    public static function syncForCustomer(Store $store, int $customerId): void
    {
        $jobs = JobOrder::where('store_id', $store->id)
            ->where('customer_id', $customerId)
            ->get();

        foreach ($jobs as $job) {
            if (! $job->measurement_id || ! Measurement::where('id', $job->measurement_id)->exists()) {
                self::syncForJob($store, $job);
            }
        }
    }
}
