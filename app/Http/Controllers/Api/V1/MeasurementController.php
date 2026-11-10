<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreMeasurementRequest;
use App\Models\Measurement;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeasurementController extends Controller
{
    /**
     * Cross-store "My Measurements" for whoever is logged in — the customer
     * counterpart to index() above, which is store-scoped and staff/owner
     * only. Same "Measurement Obfuscation" privacy rule the doc corpus
     * describes (customer-module/customer/01_account_and_auth/26 §1) is
     * satisfied by construction here: this only ever returns rows where
     * customer_id is the caller's own id, so no other customer's body
     * measurements are ever reachable through this endpoint. Read-only —
     * a customer can view what a store's staff recorded, not edit it.
     */
    public function myMeasurements(Request $request): JsonResponse
    {
        $measurements = Measurement::where('customer_id', $request->user()->id)
            ->with('store:id,name,slug,logo_path')
            ->orderBy('profile_name')
            ->orderBy('version')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $measurements,
        ]);
    }

    public function index(Store $store, Request $request): JsonResponse
    {
        // Returns every version of every profile — the frontend groups these
        // by profile_name and lets the store owner switch between versions
        // client-side (see MeasurementList's version selector), so history
        // has to be in this same response, not a separate endpoint.
        $query = $store->measurements()->with('customer:id,name,email,contact_email');

        if ($request->has('customer_id')) {
            \App\Support\JobMeasurementSync::syncForCustomer($store, (int) $request->customer_id);
            $query->where('customer_id', $request->customer_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(StoreMeasurementRequest $request, Store $store): JsonResponse
    {
        $data = $request->validated();
        $data['metrics'] = $data['metrics'] ?? []; // photo-only profiles carry no typed values
        $measurement = $store->measurements()->create($data);

        return response()->json([
            'success' => true,
            'data' => $measurement->load('customer:id,name'),
        ], 201);
    }

    public function show(Store $store, Measurement $measurement): JsonResponse
    {
        if ($measurement->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $measurement->load('customer:id,name'),
        ]);
    }

    public function update(Request $request, Store $store, Measurement $measurement): JsonResponse
    {
        if ($measurement->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // A superseded (past) version is a read-only historical record — you
        // view it, you don't edit it. Only the current version can be saved,
        // which is what actually creates the next version.
        if ($measurement->superseded_at !== null) {
            return response()->json(['success' => false, 'message' => 'This is a past version and cannot be edited. View the current version to make changes.'], 422);
        }

        $validated = $request->validate([
            'source' => 'nullable|in:store_owner,customer',
            'metrics' => 'sometimes|array',
            'measurements' => 'sometimes|array',
            'notes' => 'nullable|string',
            'photo_urls' => 'nullable|array|max:6',
            'photo_urls.*' => 'string|max:2048',
            'status' => 'nullable|in:finalized,pending_fitting',
        ]);

        if (isset($validated['measurements'])) {
            $validated['metrics'] = $validated['measurements'];
            unset($validated['measurements']);
        }

        // Saving an edit never overwrites the current row in place — it closes
        // out this version (superseded_at) and inserts the next one, so a
        // store owner can always look back at what a customer's measurements
        // were at an earlier fitting instead of losing that the moment it's
        // updated.
        $measurement->update(['superseded_at' => now()]);

        $nextVersion = $store->measurements()->create([
            'customer_id' => $measurement->customer_id,
            'source' => $validated['source'] ?? $measurement->source,
            'profile_name' => $measurement->profile_name,
            'version' => $measurement->version + 1,
            'metrics' => $validated['metrics'] ?? $measurement->metrics,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $measurement->notes,
            'photo_urls' => array_key_exists('photo_urls', $validated) ? $validated['photo_urls'] : $measurement->photo_urls,
            'status' => $validated['status'] ?? $measurement->status,
        ]);

        return response()->json([
            'success' => true,
            'data' => $nextVersion->load('customer:id,name'),
        ]);
    }

    public function destroy(Store $store, Measurement $measurement): JsonResponse
    {
        if ($measurement->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Deleting a profile removes its whole version history, not just the
        // current snapshot — otherwise old versions would be left orphaned
        // with no current row pointing at them.
        Measurement::where('store_id', $store->id)
            ->where('customer_id', $measurement->customer_id)
            ->where('profile_name', $measurement->profile_name)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Measurement deleted successfully',
        ]);
    }
}
