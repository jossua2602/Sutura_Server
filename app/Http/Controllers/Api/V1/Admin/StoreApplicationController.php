<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreApplication;
use App\Models\StoreSubscription;
use App\Notifications\ShopLoginIssuedNotification;
use App\Notifications\StoreApplicationChangesRequestedNotification;
use App\Notifications\StoreApplicationStatusNotification;
use App\Support\AdminAudit;
use App\Support\ShopLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin review queue for shop applications (sutura2's Pending Shops view,
 * mapped onto `stores.status`). Approving flips the store to approved —
 * which is what every public query already filters on — starts the
 * subscription the owner applied with, and issues the shop's login: a
 * shop-domain email plus a random temporary password the owner must change
 * on first sign-in. The credentials are returned to the admin once and
 * emailed to the owner's contact_email; they're never stored in plaintext.
 */
class StoreApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');

        $query = Store::query()
            ->with(['owner:id,name,email,contact_email', 'application.requestedPlan:id,name'])
            ->where('status', $status)
            ->orderBy($status === 'pending' ? 'created_at' : 'updated_at', $status === 'pending' ? 'asc' : 'desc');

        if ($search = trim((string) $request->input('search'))) {
            $term = '%'.strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereHas('owner', fn ($o) => $o->whereRaw('LOWER(email) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term])));
        }

        $page = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            // Uncapped per-status totals for the tab badges — never derived
            // from the paginated list above.
            'counts' => Store::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Store $store): JsonResponse
    {
        $store->load([
            'owner:id,name,email,contact_email,phone,created_at',
            'application.requestedPlan:id,name,price_monthly,price_yearly',
            'application.reviewer:id,name',
            'approvedBy:id,name',
            'branches:id,store_id,name,address,barangay,district,city,latitude,longitude,is_main',
            'subscription.plan:id,name',
        ]);

        $login = $store->owner?->email;
        $store->application?->load('changeRequester:id,name');

        return response()->json([
            'success' => true,
            'data' => [
                'store' => $store,
                // The checklist the Request Changes dialog shows.
                'change_items' => collect(StoreApplication::CHANGE_ITEMS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'documents' => $store->application?->documentList() ?? [],
                // What the approve form pre-fills; the admin can edit it.
                'suggested_login' => $login && preg_match(ShopLogin::pattern(), $login)
                    ? $login
                    : ShopLogin::suggest($store->name, $store->owner_id),
                'login_domain' => ShopLogin::domain(),
            ],
        ]);
    }

    /**
     * Streams one private verification document; admin-only via route
     * middleware. ?version=previous streams the file a re-upload replaced.
     */
    public function document(Request $request, Store $store, string $document): Response
    {
        $path = $request->query('version') === 'previous'
            ? $store->application?->previousPathFor($document)
            : $store->application?->pathFor($document);

        if (! $path || ! Storage::disk(config('filesystems.private_disk', 'local'))->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Document not found.'], 404);
        }

        return Storage::disk(config('filesystems.private_disk', 'local'))->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function approve(Request $request, Store $store): JsonResponse
    {
        if ($store->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This application has already been reviewed.'], 422);
        }

        $owner = $store->owner;
        // Stores created before applications existed already have a working
        // owner login; credentials are only issued to applicants.
        $issue = $owner && $store->application;
        $loginEmail = null;
        $temporaryPassword = null;

        if ($issue) {
            // Normalize first so "Nora.Tailoring@Sutura.Shop" typed by an
            // admin passes the lowercase-only pattern below.
            $request->merge(['login_email' => strtolower(trim((string) $request->input('login_email')))]);
            $validated = $request->validate([
                'login_email' => ['required', 'string', 'regex:'.ShopLogin::pattern()],
            ], [
                'login_email.regex' => 'The shop login must be letters, numbers, dots, dashes or underscores, ending in @'.ShopLogin::domain().'.',
            ]);
            $loginEmail = $validated['login_email'];

            if (ShopLogin::taken($loginEmail, $owner->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'That shop login is already in use. Pick another.',
                    'errors' => ['login_email' => ['That shop login is already in use.']],
                ], 422);
            }
            $temporaryPassword = ShopLogin::temporaryPassword();
        }

        DB::transaction(function () use ($request, $store, $owner, $issue, $loginEmail, $temporaryPassword) {
            if ($issue) {
                $owner->forceFill([
                    'email' => $loginEmail,
                    'password' => Hash::make($temporaryPassword),
                    'password_set_at' => now(),
                    'must_change_password' => true,
                ])->save();
            }

            $store->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'rejection_reason' => null,
            ]);
            // An open "update your application" link stops working once decided.
            $store->application?->update(['review_state' => null, 'update_token_hash' => null, 'update_token_expires_at' => null]);
        // The admin looked at the pinned location while reviewing — the shop's branches go live with it.
        $store->branches()->where('verification_status', 'pending')->update(['verification_status' => 'verified', 'verified_at' => now(), 'verified_by' => $request->user()->id]);

            $application = $store->application;
            if ($application) {
                $application->update(['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

                if ($application->requested_plan_id) {
                    StoreSubscription::create([
                        'store_id' => $store->id,
                        'plan_id' => $application->requested_plan_id,
                        'status' => 'active',
                        'billing_cycle' => $application->billing_cycle === 'yearly' ? 'yearly' : 'monthly',
                        'starts_at' => now(),
                        'ends_at' => $application->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
                    ]);
                }
            }

            AdminAudit::record($request, 'store_application_approved', Store::class, $store->id, $store->name, $store->id);
        });

        try {
            $issue
                ? $owner->notify(new ShopLoginIssuedNotification($store->fresh(), $loginEmail, $temporaryPassword))
                : $store->owner?->notify(new StoreApplicationStatusNotification($store->fresh(), 'approved'));
        } catch (\Throwable $e) {
            // The admin still sees the credentials below and can hand them over.
            Log::warning("Store {$store->id} approval email failed: {$e->getMessage()}");
        }

        return response()->json([
            'success' => true,
            'message' => "{$store->name} is approved and now live.",
            'data' => $store->fresh(),
            // Shown to the admin exactly once — never retrievable again.
            'credentials' => $issue ? [
                'login_email' => $loginEmail,
                'temporary_password' => $temporaryPassword,
                'sent_to' => $owner->contact_email ?: $owner->email,
            ] : null,
        ]);
    }

    public function reject(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:1000']);

        if ($store->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This application has already been reviewed.'], 422);
        }

        DB::transaction(function () use ($request, $store, $validated) {
            $store->update(['status' => 'rejected', 'rejection_reason' => $validated['reason']]);
            $store->application?->update([
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                'review_state' => null, 'update_token_hash' => null, 'update_token_expires_at' => null,
            ]);
            AdminAudit::record($request, 'store_application_rejected', Store::class, $store->id, $store->name, $store->id, $validated['reason']);
        });

        $this->notifyOwner($store, 'rejected');

        return response()->json(['success' => true, 'message' => 'Application rejected. The owner has been notified.', 'data' => $store->fresh()]);
    }

    /**
     * "Request Changes": the store stays pending (hidden) while the owner fixes
     * the ticked items through a private emailed link — they can't sign in yet.
     * Asking again replaces the previous link; Approve/Reject still work.
     */
    public function requestChanges(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['string', 'distinct', Rule::in(array_keys(StoreApplication::CHANGE_ITEMS))],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $application = $store->application;
        if ($store->status !== 'pending' || ! $application) {
            return response()->json(['success' => false, 'message' => 'Only a pending shop application can be sent back for changes.'], 422);
        }

        $token = Str::random(64);
        $expires = now()->addDays(StoreApplication::UPDATE_LINK_DAYS);

        DB::transaction(function () use ($request, $store, $application, $validated, $token, $expires) {
            $application->update([
                'review_state' => 'changes_requested',
                'change_request_items' => array_values($validated['items']),
                'change_request_note' => trim($validated['note']),
                'change_requested_at' => now(),
                'change_requested_by' => $request->user()->id,
                'update_token_hash' => hash('sha256', $token),
                'update_token_expires_at' => $expires,
            ]);
            AdminAudit::record($request, 'store_application_changes_requested', Store::class, $store->id, $store->name, $store->id, trim($validated['note']));
        });

        $updateUrl = rtrim((string) config('app.frontend_url'), '/').'/register/store/update?token='.$token;
        $sentTo = $store->owner?->contact_email ?: $store->owner?->email;
        $emailed = false;
        try {
            $store->owner?->notify(new StoreApplicationChangesRequestedNotification(
                $store->fresh(), $application->fresh()->requestedItems(), trim($validated['note']), $updateUrl, $expires->format('F j, Y'),
            ));
            $emailed = (bool) $store->owner;
        } catch (\Throwable $e) {
            Log::warning("Store application change-request email failed for store {$store->id}: {$e->getMessage()}");
        }

        return response()->json([
            'success' => true,
            'message' => $emailed
                ? "Change request sent to {$sentTo}."
                : 'Change request saved, but the email could not be sent. Copy the link below and send it to the owner yourself.',
            // Only when the email failed, so the admin can still pass the link on.
            'update_url' => $emailed ? null : $updateUrl,
        ]);
    }

    /**
     * QUEUE_CONNECTION=sync sends mail in-request; a mail outage must not
     * turn an already-committed decision into a 500.
     */
    private function notifyOwner(Store $store, string $decision): void
    {
        try {
            $store->owner?->notify(new StoreApplicationStatusNotification($store->fresh(), $decision));
        } catch (\Throwable $e) {
            Log::warning("Store application {$decision} email failed for store {$store->id}: {$e->getMessage()}");
        }
    }
}
