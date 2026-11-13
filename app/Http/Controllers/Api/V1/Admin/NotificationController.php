<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomApparelType;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Models\StoreSubscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NotificationController extends Controller
{
    /**
     * Get platform notifications and pending operational action counts for admin console.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = collect();

        // 1. Pending Store Applications (e.g. Moretti Tailoring Shop)
        $pendingStores = Store::with(['owner:id,name,email,contact_email', 'application:id,store_id,review_state,resubmitted_at'])
            ->where('status', 'pending')
            ->latest()
            ->take(15)
            ->get();

        foreach ($pendingStores as $store) {
            $state = $store->application?->review_state;
            // Waiting on the owner's changes: nothing for the admin to do yet.
            if ($state === 'changes_requested') {
                continue;
            }
            if ($state === 'resubmitted' && $store->application->resubmitted_at) {
                // New id per resubmission, so it shows as unread again.
                $items->push([
                    'id' => 'app_resubmitted_' . $store->id . '_' . $store->application->resubmitted_at->timestamp,
                    'type' => 'store_application',
                    'category' => 'applications',
                    'title' => 'Application Updated',
                    'message' => "{$store->name} sent the changes you asked for",
                    'detail' => $store->owner ? "Updated by {$store->owner->name}" : null,
                    'link' => "/admin/applications/{$store->id}",
                    'action_label' => 'Review Changes',
                    'created_at' => $store->application->resubmitted_at->toISOString(),
                    'badge' => 'Resubmitted',
                    'severity' => 'amber',
                ]);
                continue;
            }
            $items->push([
                'id' => 'app_' . $store->id,
                'type' => 'store_application',
                'category' => 'applications',
                'title' => 'New Store Application',
                'message' => "{$store->name} submitted a store application",
                'detail' => $store->owner ? "Submitted by {$store->owner->name}" . ($store->city ? " · {$store->city}" : '') : ($store->city ? "Location: {$store->city}" : null),
                'link' => "/admin/applications/{$store->id}",
                'action_label' => 'Review Application',
                'created_at' => $store->created_at?->toISOString() ?? now()->toISOString(),
                'badge' => 'Pending Review',
                'severity' => 'amber',
            ]);
        }

        // 2. Branch Location Verifications
        $pendingBranches = StoreBranch::where('verification_status', 'pending')
            ->whereHas('store', fn ($q) => $q->where('status', 'approved'))
            ->with('store:id,name')
            ->latest()
            ->take(15)
            ->get();

        foreach ($pendingBranches as $branch) {
            $storeName = $branch->store?->name ?? 'Store';
            $items->push([
                'id' => 'branch_' . $branch->id,
                'type' => 'branch_verification',
                'category' => 'branches',
                'title' => 'Branch Verification',
                'message' => "{$branch->name} pin waiting for location verification",
                'detail' => "{$storeName}" . ($branch->city ? " · {$branch->city}" : ''),
                'link' => '/admin/branches',
                'action_label' => 'Verify Location',
                'created_at' => $branch->created_at?->toISOString() ?? now()->toISOString(),
                'badge' => 'Location Pin',
                'severity' => 'blue',
            ]);
        }

        // 3. Subscription Upgrades / Payments
        $pendingUpgrades = SubscriptionUpgradeRequest::inLedger()->where('status', 'pending')
            ->where('type', '!=', 'new_shop') // decided with the shop application
            ->with(['store:id,name', 'plan:id,name'])
            ->latest()
            ->take(15)
            ->get();

        $typeTitles = ['upgrade' => 'Plan Upgrade Payment', 'renewal' => 'Plan Renewal Payment', 'downgrade' => 'Plan Change Payment'];
        foreach ($pendingUpgrades as $upg) {
            $planName = $upg->plan?->name ?? 'Tier';
            $storeName = $upg->store?->name ?? 'Store';
            // Paid with GCash online but the plan couldn't start on its own (e.g. over the staff limit).
            $online = $upg->isOnline();
            $items->push([
                'id' => ($online ? 'gcash_check_' : 'upgrade_') . $upg->id,
                'type' => 'subscription_upgrade',
                'category' => 'upgrades',
                'title' => $online ? 'Paid, Needs Your Check' : ($typeTitles[$upg->type] ?? 'Plan Payment'),
                'message' => "{$storeName} paid for {$planName}" . ($upg->billing_cycle ? " ({$upg->billing_cycle})" : ''),
                'detail' => '₱' . number_format((float) $upg->quoted_price, 2) . ($online
                    ? ' · Paid with GCash, but the plan could not start on its own'
                    : ' · GCash receipt waiting for your check'),
                'link' => '/admin/payments?review=' . $upg->id,
                'action_label' => 'Review Payment',
                'created_at' => ($online ? $upg->gateway_paid_at : $upg->created_at)?->toISOString() ?? now()->toISOString(),
                'badge' => $planName,
                'severity' => $online ? 'amber' : 'emerald',
            ]);
        }

        // 3b. GCash payments made online in the last 14 days (they confirm themselves, so this is
        // the admin's only heads-up). One still waiting for an admin is already listed above.
        $gcashPaid = SubscriptionUpgradeRequest::where('channel', 'online')->where('gateway_status', 'paid')
            ->where('gateway_paid_at', '>=', now()->subDays(14))
            ->where(fn ($q) => $q->where('status', '!=', 'pending')->orWhere('type', 'new_shop'))
            ->with(['store:id,name', 'plan:id,name'])
            ->latest('gateway_paid_at')
            ->take(15)
            ->get();

        foreach ($gcashPaid as $paid) {
            $planName = $paid->plan?->name ?? 'Tier';
            $storeName = $paid->store?->name ?? 'Store';
            $newShop = $paid->type === 'new_shop';
            $items->push([
                'id' => 'gcash_paid_' . $paid->id,
                'type' => 'gcash_payment',
                'category' => 'upgrades',
                'title' => 'GCash Payment Received',
                'message' => "{$storeName} paid ₱" . number_format((float) $paid->quoted_price, 2) . " for {$planName}",
                'detail' => match (true) {
                    $newShop => 'New shop · paid with GCash',
                    $paid->status === 'approved' => ucfirst($paid->type) . ' · plan updated automatically',
                    default => ucfirst($paid->type),
                },
                // Always the payment: the application has its own "New Store Application" alert.
                'link' => '/admin/payments?review=' . $paid->id,
                'action_label' => 'View Payment',
                'created_at' => $paid->gateway_paid_at->toISOString(),
                'badge' => 'GCash',
                'severity' => 'emerald',
            ]);
        }

        // 3c. Refunds that failed (retry them) or are still on their way to the payer.
        $refunds = SubscriptionUpgradeRequest::whereIn('refund_status', ['failed', 'pending'])
            ->with(['store:id,name'])
            ->latest('updated_at')
            ->take(10)
            ->get();

        foreach ($refunds as $refund) {
            $failed = $refund->refund_status === 'failed';
            $storeName = $refund->store?->name ?? 'Store';
            $amount = '₱' . number_format((float) $refund->refund_amount, 2);
            $items->push([
                // A new id per state change, so a refund that fails after processing shows as unread again.
                'id' => 'refund_' . $refund->refund_status . '_' . $refund->id . '_' . $refund->updated_at?->timestamp,
                'type' => 'gcash_refund',
                'category' => 'upgrades',
                'title' => $failed ? 'Refund Failed' : 'Refund Processing',
                'message' => $failed ? "The {$amount} refund to {$storeName} did not go through" : "{$amount} refund to {$storeName} is on its way",
                'detail' => $failed ? 'Open the payment and try the refund again' : 'GCash is still processing it',
                'link' => '/admin/payments?review=' . $refund->id,
                'action_label' => $failed ? 'Retry Refund' : 'Check Refund',
                'created_at' => $refund->updated_at?->toISOString() ?? now()->toISOString(),
                'badge' => 'Refund',
                'severity' => $failed ? 'rose' : 'blue',
            ]);
        }

        // 4. Recent Subscription Activations / Tier Purchases
        $recentSubs = StoreSubscription::whereIn('status', ['active', 'trial'])
            ->with(['store:id,name', 'plan:id,name'])
            ->latest('starts_at')
            ->take(8)
            ->get();

        foreach ($recentSubs as $sub) {
            $planName = $sub->plan?->name ?? 'Tier';
            $storeName = $sub->store?->name ?? 'Store';
            $cycle = $sub->billing_cycle ? ucfirst($sub->billing_cycle) : 'Trial';
            $items->push([
                'id' => 'sub_' . $sub->id,
                'type' => 'subscription_tier',
                'category' => 'subscriptions',
                'title' => 'Subscription Tier Active',
                'message' => "{$storeName} subscribed to {$planName} tier",
                'detail' => "{$cycle} plan" . ($sub->ends_at ? ' · Renews ' . Carbon::parse($sub->ends_at)->format('M d, Y') : ''),
                'link' => '/admin/reports',
                'action_label' => 'View Subscriptions',
                'created_at' => ($sub->starts_at ?? $sub->created_at)?->toISOString() ?? now()->toISOString(),
                'badge' => $planName,
                'severity' => 'purple',
            ]);
        }

        // 5. Custom Apparel Category Review
        $pendingApparel = CustomApparelType::where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        foreach ($pendingApparel as $apparel) {
            $items->push([
                'id' => 'apparel_' . $apparel->id,
                'type' => 'apparel_review',
                'category' => 'apparel',
                'title' => 'Apparel Category Review',
                'message' => "Custom category '{$apparel->label}' submitted for review",
                'detail' => 'Pending taxonomy validation',
                'link' => '/admin/apparel',
                'action_label' => 'Review Apparel',
                'created_at' => $apparel->created_at?->toISOString() ?? now()->toISOString(),
                'badge' => 'Apparel Taxonomy',
                'severity' => 'indigo',
            ]);
        }

        // 6. Open Support Tickets
        $openTickets = SupportTicket::whereIn('status', ['open', 'in_progress'])
            ->with(['submittedBy:id,name', 'store:id,name'])
            ->latest()
            ->take(8)
            ->get();

        foreach ($openTickets as $ticket) {
            $sender = $ticket->submittedBy?->name;
            $items->push([
                'id' => 'ticket_' . $ticket->id,
                'type' => 'support_ticket',
                'category' => 'support',
                'title' => 'Support Ticket #' . $ticket->id,
                'message' => $ticket->subject,
                'detail' => ($ticket->store ? $ticket->store->name . ' · ' : '') . ucfirst($ticket->priority ?? 'medium') . ' priority' . ($sender ? " · From {$sender}" : ''),
                // The tickets page opens this ticket from ?ticket= (there is no per-ticket page).
                'link' => '/admin/tickets?ticket=' . $ticket->id,
                'action_label' => 'View Ticket',
                'created_at' => $ticket->created_at?->toISOString() ?? now()->toISOString(),
                'badge' => ucfirst($ticket->status ?? 'open'),
                'severity' => ($ticket->priority ?? '') === 'high' ? 'rose' : 'amber',
            ]);
        }

        // Sort by timestamp newest first
        $sorted = $items->sortByDesc('created_at')->values();

        // Read status management
        $readIds = Cache::get("admin_{$user->id}_read_notifications", []);
        $markedAllReadAt = Cache::get("admin_{$user->id}_marked_all_read_at");

        $enriched = $sorted->map(function ($alert) use ($readIds, $markedAllReadAt) {
            $isRead = in_array($alert['id'], $readIds, true);
            if (! $isRead && $markedAllReadAt) {
                $alertTime = Carbon::parse($alert['created_at']);
                $markedTime = Carbon::parse($markedAllReadAt);
                if ($alertTime->lte($markedTime)) {
                    $isRead = true;
                }
            }

            $alert['read_at'] = $isRead ? ($markedAllReadAt ?? now()->toISOString()) : null;

            return $alert;
        });

        $unreadCount = $enriched->filter(fn ($a) => empty($a['read_at']))->count();

        // Real-time pending operational counts for sidebar indicators
        $counts = [
            'applications' => Store::where('status', 'pending')->count(),
            'branches' => StoreBranch::where('verification_status', 'pending')->whereHas('store', fn ($q) => $q->where('status', 'approved'))->count(),
            // Payments waiting for an admin (a new shop's payment is decided with its application).
            'upgrades' => SubscriptionUpgradeRequest::inLedger()->where('status', 'pending')->where('type', '!=', 'new_shop')->count(),
            'apparel' => CustomApparelType::where('status', 'pending')->count(),
            'tickets' => SupportTicket::whereIn('status', ['open', 'in_progress'])->count(),
        ];
        $counts['total_pending'] = $counts['applications'] + $counts['branches'] + $counts['upgrades'] + $counts['apparel'];

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $enriched->values(),
                'unread_count' => $unreadCount,
                'counts' => $counts,
            ],
        ]);
    }

    /**
     * Mark all notifications as read for current admin.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        Cache::forever("admin_{$user->id}_marked_all_read_at", now()->toISOString());
        Cache::forget("admin_{$user->id}_read_notifications");

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'unread_count' => 0,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $readIds = Cache::get("admin_{$user->id}_read_notifications", []);
        if (! in_array($id, $readIds, true)) {
            $readIds[] = $id;
            Cache::forever("admin_{$user->id}_read_notifications", $readIds);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }
}
