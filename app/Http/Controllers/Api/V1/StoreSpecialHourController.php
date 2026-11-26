<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreSpecialHour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreSpecialHourController extends Controller
{
    public function index(Store $store, Request $request): JsonResponse
    {
        $query = $store->specialHours()->with('branch:id,name')->orderBy('start_date', 'desc');

        // The management UI needs to see past entries too — otherwise an
        // owner has no way to reach (edit/delete) a lapsed entry once its
        // end_date passes, even to fix a typo. Only filter to "upcoming or
        // active" when explicitly asked for.
        if (! $request->boolean('include_past')) {
            // Asia/Manila, matching Store::getActiveSpecialHoursAttribute() —
            // the app's default timezone is UTC.
            $query->where('end_date', '>=', now('Asia/Manila')->toDateString());
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            // null/omitted = store-wide (every branch); a real ID scopes the
            // closure/hours to just that one branch.
            'store_branch_id' => ['nullable', 'integer', Rule::exists('store_branches', 'id')->where('store_id', $store->id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_closed' => ['required', 'boolean'],
            'special_open_time' => ['nullable', 'string', 'required_if:is_closed,false'],
            'special_close_time' => ['nullable', 'string', 'required_if:is_closed,false'],
            'announcement_message' => ['nullable', 'string', 'max:2000'],
            'announcement_image_url' => ['nullable', 'string', 'max:1000'],
        ]);

        $specialHour = $store->specialHours()->create($validated);

        return response()->json([
            'success' => true,
            'data' => $specialHour->load('branch:id,name'),
        ], 201);
    }

    public function update(Request $request, Store $store, StoreSpecialHour $specialHour): JsonResponse
    {
        if ($specialHour->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => "This special schedule isn't part of your shop."], 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'store_branch_id' => ['nullable', 'integer', Rule::exists('store_branches', 'id')->where('store_id', $store->id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_closed' => ['required', 'boolean'],
            'special_open_time' => ['nullable', 'string', 'required_if:is_closed,false'],
            'special_close_time' => ['nullable', 'string', 'required_if:is_closed,false'],
            'announcement_message' => ['nullable', 'string', 'max:2000'],
            'announcement_image_url' => ['nullable', 'string', 'max:1000'],
        ]);

        $specialHour->update($validated);

        return response()->json([
            'success' => true,
            'data' => $specialHour->load('branch:id,name'),
        ]);
    }

    public function destroy(Store $store, StoreSpecialHour $specialHour): JsonResponse
    {
        if ($specialHour->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => "This special schedule isn't part of your shop."], 403);
        }

        $specialHour->delete();

        return response()->json([
            'success' => true,
            'message' => 'Special schedule removed.',
        ]);
    }
}
