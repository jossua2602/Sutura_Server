<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

/**
 * The Welcome Guide's checklist: which setup steps the shop has really done,
 * read from its own records (not from whether a tour was watched), plus
 * whether the owner has already seen the guide.
 */
class StoreSetupController extends Controller
{
    public function progress(Store $store): JsonResponse
    {
        $hours = $store->operating_hours;

        $steps = [
            // What customers see first: a logo, a description and opening hours.
            'profile' => filled($store->logo_path) && filled($store->description) && ! empty($hours),
            // The application already creates (and approval verifies) the main branch
            // with its address and contact number, so that alone doesn't count. Done
            // once the owner has really worked on branches: a second branch, a guide
            // photo, or branch opening hours.
            'branches' => $store->branches()->live()->exists() && (
                $store->branches()->count() > 1
                || $store->branches()->where(fn ($q) => $q->whereNotNull('guide_image_url')->where('guide_image_url', '!=', '')
                    ->orWhereNotNull('operating_hours'))->exists()
            ),
            'services' => $store->services()->where('is_active', true)->exists(),
            'catalog' => $store->catalogItems()->where('is_active', true)->exists(),
            'staff' => $store->staff()->exists(),
            'appointments' => $store->appointments()->exists(),
            'jobs' => $store->jobOrders()->exists(),
            'payments' => Payment::whereHas('jobOrder', fn ($q) => $q->where('store_id', $store->id))->exists(),
        ];

        // What's still missing on the profile, so the guide can say why Step 1 isn't ticked yet.
        $profileMissing = array_keys(array_filter([
            'logo' => ! filled($store->logo_path),
            'description' => ! filled($store->description),
            'opening hours' => empty($hours),
        ]));

        return response()->json([
            'success' => true,
            'data' => [
                'steps' => $steps,
                'missing' => ['profile' => $profileMissing],
                'done' => count(array_filter($steps)),
                'total' => count($steps),
                'welcome_seen_at' => $store->welcome_seen_at,
            ],
        ]);
    }

    /** First time only: later calls keep the original date. */
    public function markWelcomeSeen(Store $store): JsonResponse
    {
        if ($store->welcome_seen_at === null) {
            $store->forceFill(['welcome_seen_at' => now()])->save();
        }

        return response()->json(['success' => true, 'data' => ['welcome_seen_at' => $store->welcome_seen_at]]);
    }
}
