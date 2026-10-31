<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => SubscriptionPlan::all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'description' => 'nullable|string',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'max_staff' => 'required|integer',
            // Branch Shops beyond the Main Shop: -1 = unlimited, 0 = main shop only.
            'max_branches' => ['nullable', 'integer', 'min:-1'],
            'max_services' => 'required|integer',
            'max_appointments_per_month' => 'required|integer',
            'features' => 'nullable|array',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $plan = SubscriptionPlan::create($validated);

        return response()->json([
            'success' => true,
            'data' => $plan,
        ], 201);
    }

    /**
     * Edit pricing/limits/perks, or retire a plan with is_active=false.
     * No delete: store_subscriptions rows reference plans by FK, and a
     * retired plan simply stops appearing on the public application form.
     * The slug is left alone — code looks plans up by it (e.g. 'premium').
     */
    public function update(Request $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:50',
            'description' => 'nullable|string|max:500',
            'price_monthly' => 'sometimes|numeric|min:0',
            'price_yearly' => 'sometimes|numeric|min:0',
            'max_staff' => 'sometimes|integer|min:-1',
            // Branch Shops beyond the Main Shop: -1 = unlimited, 0 = main shop only.
            'max_branches' => ['sometimes', 'integer', 'min:-1'],
            'max_services' => 'sometimes|integer|min:-1',
            'max_appointments_per_month' => 'sometimes|integer|min:-1',
            'features' => 'nullable|array|max:20',
            'features.*' => 'string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $subscriptionPlan->update($validated);

        return response()->json(['success' => true, 'data' => $subscriptionPlan->fresh()]);
    }
}
