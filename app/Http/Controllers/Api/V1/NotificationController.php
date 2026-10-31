<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StoreBranch;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NotificationController extends Controller
{
    /**
     * The user's notifications for the branch being viewed, plus store-wide
     * ones (branch_id null).
     * - A manager/staff account pinned to a branch always gets that branch.
     * - The owner is notified about the main shop: with no ?branch_id (and in
     *   "All Branches") they get their main branches; picking a branch shows
     *   that branch's notifications instead.
     * - Anyone else (customers) gets everything — their rows carry no branch scope.
     */
    private function feed(Request $request): MorphMany
    {
        $query = $request->user()->notifications();
        if (($branchIds = $this->branchIds($request)) !== null) {
            $query->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds));
        }

        return $query;
    }

    /** @return int[]|null null = no branch filter */
    private function branchIds(Request $request): ?array
    {
        $user = $request->user();
        if (! $user->hasRole('store_owner')) {
            return $user->staffProfile?->store_branch_id ? [(int) $user->staffProfile->store_branch_id] : null;
        }

        if ($request->filled('branch_id')) {
            return [(int) $request->input('branch_id')];
        }

        return StoreBranch::whereIn('store_id', $user->stores()->select('id'))
            ->where('is_main', true)->pluck('id')->all();
    }

    private function unreadCount(Request $request): int
    {
        return $this->feed($request)->whereNull('read_at')->count();
    }

    /**
     * Get the authenticated user's notifications.
     * Supports both quick dropdown mode (default: limit 30) and paginated
     * full table mode (with ?page=, ?per_page=, ?search=, ?status=).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Paginated mode for the full notifications hub page
        if ($request->has('page') || $request->has('per_page') || $request->boolean('paginate')) {
            $query = $this->feed($request)->latest();

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('data', 'like', "%{$search}%");
                });
            }

            if ($request->filled('status')) {
                if ($request->input('status') === 'unread') {
                    $query->whereNull('read_at');
                } elseif ($request->input('status') === 'read') {
                    $query->whereNotNull('read_at');
                }
            }

            $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
            $paginated = $query->paginate($perPage);

            $items = collect($paginated->items())->map(function ($n) {
                return [
                    'id' => $n->id,
                    'type' => $n->type,
                    'data' => is_string($n->data) ? json_decode($n->data, true) : $n->data,
                    'read_at' => $n->read_at ? (is_string($n->read_at) ? $n->read_at : $n->read_at->toISOString()) : null,
                    'created_at' => is_string($n->created_at) ? $n->created_at : $n->created_at?->toISOString(),
                ];
            })->values()->all();

            return response()->json([
                'success' => true,
                'data' => $items,
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
                'unread_count' => $this->unreadCount($request),
            ]);
        }

        // Quick dropdown mode (cached for 15s). A branch-filtered feed skips
        // the cache: the forget() calls elsewhere only clear this one key.
        $cacheKey = "user_notifications_{$user->id}";
        $cacheable = $this->branchIds($request) === null && ! app()->environment('testing');

        if ($cacheable) {
            $cached = Cache::driver('file')->get($cacheKey);
            if ($cached && is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
                return response()->json($cached);
            }
        }

        $limit = max(1, min(50, (int) $request->input('limit', 30)));
        $rawList = $this->feed($request)->latest()->limit($limit)->get();
        $items = $rawList->map(function ($n) {
            return [
                'id' => $n->id,
                'type' => $n->type,
                'data' => is_string($n->data) ? json_decode($n->data, true) : $n->data,
                'read_at' => $n->read_at ? (is_string($n->read_at) ? $n->read_at : $n->read_at->toISOString()) : null,
                'created_at' => is_string($n->created_at) ? $n->created_at : $n->created_at?->toISOString(),
            ];
        })->values()->all();

        $payload = [
            'success' => true,
            'data' => $items,
            'unread_count' => $this->unreadCount($request),
        ];

        if ($cacheable) {
            Cache::driver('file')->put($cacheKey, $payload, 15);
        }

        return response()->json($payload);
    }

    /**
     * Get a specific notification by ID.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $notification,
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();
        }

        Cache::driver('file')->forget("user_notifications_{$request->user()->id}");

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    /**
     * Mark multiple notifications as read at once.
     */
    public function bulkRead(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);

        if (is_array($ids) && count($ids) > 0) {
            $request->user()->notifications()->whereIn('id', $ids)->update(['read_at' => now()]);
            Cache::driver('file')->forget("user_notifications_{$request->user()->id}");
        }

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    /**
     * Mark all notifications as read â€” only those in the branch being viewed.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->feed($request)->whereNull('read_at')->update(['read_at' => now()]);

        Cache::driver('file')->forget("user_notifications_{$request->user()->id}");

        return response()->json([
            'success' => true,
            'unread_count' => 0,
        ]);
    }

    /**
     * Mark a specific notification back as unread.
     */
    public function markAsUnread(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsUnread();
        }

        Cache::driver('file')->forget("user_notifications_{$request->user()->id}");

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    /**
     * Remove a single notification.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->delete();
        }

        Cache::driver('file')->forget("user_notifications_{$request->user()->id}");

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    /**
     * Delete multiple notifications at once.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);

        if (is_array($ids) && count($ids) > 0) {
            $request->user()->notifications()->whereIn('id', $ids)->delete();
            Cache::driver('file')->forget("user_notifications_{$request->user()->id}");
        }

        return response()->json([
            'success' => true,
            'unread_count' => $this->unreadCount($request),
        ]);
    }

    /**
     * Clear all notifications for the authenticated user — only those in the
     * branch being viewed, so clearing one branch never wipes another's.
     */
    public function clearAll(Request $request): JsonResponse
    {
        $this->feed($request)->delete();
        Cache::driver('file')->forget("user_notifications_{$request->user()->id}");

        return response()->json([
            'success' => true,
            'unread_count' => 0,
        ]);
    }

    /**
     * Get user notification delivery preferences.
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $key = "user_notification_prefs_{$user->id}";
        $prefs = Cache::driver('file')->get($key);

        return response()->json([
            'success' => true,
            'data' => $prefs ?: $this->defaultPreferences(),
        ]);
    }

    /**
     * Update user notification delivery preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $key = "user_notification_prefs_{$user->id}";
        $preferences = $request->input('preferences', []);

        Cache::driver('file')->forever($key, $preferences);

        return response()->json([
            'success' => true,
            'message' => 'Notification preferences saved successfully',
            'data' => $preferences,
        ]);
    }

    /**
     * Default notification delivery channel preferences matrix.
     */
    private function defaultPreferences(): array
    {
        return [
            ['id' => 'new_job_order', 'event' => 'A new job order is created', 'email' => true, 'mobile' => true],
            ['id' => 'job_cutting', 'event' => 'Job order moves to Cutting stage', 'email' => true, 'mobile' => true],
            ['id' => 'job_sewing', 'event' => 'Job order moves to Sewing & Assembly stage', 'email' => true, 'mobile' => true],
            ['id' => 'job_ready_for_fitting', 'event' => 'Job order is ready for customer fitting', 'email' => true, 'mobile' => true],
            ['id' => 'job_final_adjustments', 'event' => 'Job order final adjustments in progress', 'email' => false, 'mobile' => true],
            ['id' => 'job_ready_for_pickup', 'event' => 'Job order is ready for pickup', 'email' => true, 'mobile' => true],
            ['id' => 'job_completed', 'event' => 'Job order is completed', 'email' => true, 'mobile' => true],
            ['id' => 'appointment_booked', 'event' => 'A customer books an appointment', 'email' => true, 'mobile' => true],
            ['id' => 'appointment_rescheduled', 'event' => 'An appointment is rescheduled or cancelled', 'email' => true, 'mobile' => true],
            ['id' => 'payment_received', 'event' => 'A downpayment or full payment is received', 'email' => true, 'mobile' => true],
            ['id' => 'payment_rejected', 'event' => 'A payment proof is rejected or refunded', 'email' => true, 'mobile' => true],
            ['id' => 'overdue_jobs_digest', 'event' => 'Daily alert for overdue job orders', 'email' => true, 'mobile' => true],
            ['id' => 'unclaimed_pickups_digest', 'event' => 'Daily alert for unclaimed customer pickups', 'email' => true, 'mobile' => true],
            ['id' => 'jobs_on_hold_digest', 'event' => 'Alert for jobs placed on hold', 'email' => false, 'mobile' => true],
            ['id' => 'staff_assigned', 'event' => 'Staff member assigned to a production stage', 'email' => true, 'mobile' => true],
            ['id' => 'customer_review', 'event' => 'Customer leaves a store review or rating', 'email' => true, 'mobile' => true],
            ['id' => 'subscription_alert', 'event' => 'Subscription renewal and billing notices', 'email' => true, 'mobile' => true],
        ];
    }
}
