<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionUpgradeRequest;
use App\Support\PlatformPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Admin review queue for paid plan upgrade / renewal payments. */
class SubscriptionUpgradeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');

        $page = SubscriptionUpgradeRequest::query()
            ->inLedger()
            ->with(['store:id,name,slug', 'plan:id,name', 'requester:id,name,email,contact_email', 'reviewer:id,name'])
            ->where('status', $status)
            ->orderBy('created_at', $status === 'pending' ? 'asc' : 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'counts' => SubscriptionUpgradeRequest::query()->inLedger()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function receipt(SubscriptionUpgradeRequest $upgradeRequest): Response
    {
        $path = $upgradeRequest->payment_receipt_path;

        if (! $path || ! Storage::disk(config('filesystems.private_disk', 'local'))->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Receipt not found.'], 404);
        }

        return Storage::disk(config('filesystems.private_disk', 'local'))->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    // Older endpoints kept for compatibility; the rules live in PlatformPayments
    // (the Payments page uses Admin\PlatformPaymentController).
    public function approve(Request $request, SubscriptionUpgradeRequest $upgradeRequest): JsonResponse
    {
        return response()->json(['success' => true, 'message' => PlatformPayments::approve($request, $upgradeRequest)]);
    }

    public function reject(Request $request, SubscriptionUpgradeRequest $upgradeRequest): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        PlatformPayments::reject($request, $upgradeRequest, $validated['reason']);

        return response()->json(['success' => true, 'message' => 'Payment rejected. The owner was notified.']);
    }
}
