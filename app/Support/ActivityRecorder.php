<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Oversee platform-wide activity": one Activity Log row for every change
 * anyone makes on SUTURA — admin, shop owner, branch manager, staff, customer
 * or guest. Called by App\Http\Middleware\RecordActivity after each successful
 * write request (reads/page views are not logged), and directly for sign-in,
 * sign-up and password reset, where nobody is signed in yet.
 *
 * Only the route, the subject's id and display name are stored — never the
 * request body, so passwords and receipts never land in the log.
 */
class ActivityRecorder
{
    /**
     * "METHOD uri" (without api/v1/) => [action key, what happened].
     * Anything not listed still gets logged with a description built from its URL.
     */
    private const ACTIONS = [
        // Accounts & sign-in
        'POST auth/logout' => ['signed_out', 'Signed out'],
        'PUT profile/personal' => ['profile_updated', 'Updated their profile'],
        'PUT profile/password' => ['password_changed', 'Changed their password'],
        'PUT profile/availability' => ['availability_updated', 'Updated their availability'],
        'PUT profile/text-messages' => ['sms_preference_updated', 'Updated their text-message settings'],

        // Shop registration & profile
        'POST store-applications' => ['store_application_submitted', 'Submitted a shop application'],
        'POST stores' => ['store_created', 'Created a shop'],
        'PUT stores/{store}' => ['store_updated', 'Updated the shop profile'],
        'DELETE stores/{store}' => ['store_deleted', 'Deleted the shop'],
        'POST stores/{store}/subscription' => ['subscription_changed', 'Changed the subscription plan'],
        'POST stores/{store}/subscription/upgrade-requests' => ['plan_payment_submitted', 'Sent a plan payment for review'],
        'PUT stores/{store}/services/{service}/discount' => ['service_discount_set', 'Set a discount on a service'],
        'DELETE stores/{store}/services/{service}/discount' => ['service_discount_removed', 'Removed a service discount'],
        'POST stores/{store}/subscription/cancel' => ['subscription_cancelled', 'Cancelled the subscription (runs until its end date)'],
        'DELETE stores/{store}/subscription/cancel' => ['subscription_resumed', 'Undid the subscription cancellation'],
        'POST stores/{store}/branches' => ['branch_created', 'Added a branch'],
        'PUT stores/{store}/branches/{branch}' => ['branch_updated', 'Updated a branch'],
        'PUT stores/{store}/branches/{branch}/set-main' => ['branch_set_main', 'Set the main branch'],
        'DELETE stores/{store}/branches/{branch}' => ['branch_deleted', 'Deleted a branch'],
        'POST stores/{store}/special-hours' => ['special_hours_added', 'Added special hours'],
        'PUT stores/{store}/special-hours/{specialHour}' => ['special_hours_updated', 'Updated special hours'],
        'DELETE stores/{store}/special-hours/{specialHour}' => ['special_hours_deleted', 'Deleted special hours'],
        'POST stores/{store}/posts' => ['post_created', 'Published a shop post'],
        'PUT stores/{store}/posts/{post}' => ['post_updated', 'Updated a shop post'],
        'DELETE stores/{store}/posts/{post}' => ['post_deleted', 'Deleted a shop post'],
        'POST stores/{store}/payment-methods' => ['payment_method_added', 'Added a payment method'],
        'PUT stores/{store}/payment-methods/{paymentMethod}' => ['payment_method_updated', 'Updated a payment method'],
        'DELETE stores/{store}/payment-methods/{paymentMethod}' => ['payment_method_deleted', 'Deleted a payment method'],
        'PUT stores/{store}/sms/settings' => ['sms_settings_updated', 'Updated SMS settings'],
        'PUT stores/{store}/sms/{message}' => ['sms_edited', 'Edited a text message'],
        'POST stores/{store}/sms/approve-all' => ['sms_approved_all', 'Approved all pending text messages'],
        'POST stores/{store}/sms/{message}/approve' => ['sms_approved', 'Approved a text message'],
        'POST stores/{store}/sms/{message}/cancel' => ['sms_cancelled', 'Cancelled a text message'],

        // Staff
        'POST stores/{store}/staff' => ['staff_added', 'Added a staff member'],
        'PUT stores/{store}/staff/{staff}' => ['staff_updated', 'Updated a staff member'],
        'DELETE stores/{store}/staff/{staff}' => ['staff_removed', 'Removed a staff member'],

        // Catalog, services & packages
        'POST stores/{store}/catalog' => ['catalog_item_created', 'Added a catalog design'],
        'PUT stores/{store}/catalog/{catalog}' => ['catalog_item_updated', 'Updated a catalog design'],
        'DELETE stores/{store}/catalog/{catalog}' => ['catalog_item_deleted', 'Deleted a catalog design'],
        'POST stores/{store}/services' => ['service_created', 'Added a service'],
        'PUT stores/{store}/services/{service}' => ['service_updated', 'Updated a service'],
        'DELETE stores/{store}/services/{service}' => ['service_deleted', 'Deleted a service'],
        'POST stores/{store}/service-packages' => ['package_created', 'Added a combo package'],
        'PUT stores/{store}/service-packages/{servicePackage}' => ['package_updated', 'Updated a combo package'],
        'DELETE stores/{store}/service-packages/{servicePackage}' => ['package_deleted', 'Deleted a combo package'],

        // Customers & measurements
        'POST stores/{store}/customers' => ['customer_added', 'Added a customer'],
        'PUT stores/{store}/customers/{customer}' => ['customer_updated', 'Updated a customer'],
        'DELETE stores/{store}/customers/{customer}' => ['customer_deleted', 'Deleted a customer'],
        'POST stores/{store}/measurements' => ['measurement_recorded', 'Recorded measurements'],
        'PUT stores/{store}/measurements/{measurement}' => ['measurement_updated', 'Updated measurements'],
        'DELETE stores/{store}/measurements/{measurement}' => ['measurement_deleted', 'Deleted measurements'],

        // Appointments
        'POST stores/{store}/appointments' => ['appointment_booked', 'Booked an appointment'],
        'POST stores/{store}/appointments/follow-up' => ['follow_up_booked', 'Booked a follow-up appointment'],
        'PUT stores/{store}/appointments/{appointment}' => ['appointment_updated', 'Updated an appointment'],
        'PUT stores/{store}/appointments/{appointment}/assign' => ['appointment_staff_assigned', 'Assigned staff to an appointment'],
        'PUT stores/{store}/appointments/{appointment}/verify-payment' => ['appointment_payment_verified', 'Checked an appointment deposit'],
        'POST stores/{store}/appointments/{appointment}/complete' => ['appointment_completed', 'Completed an appointment'],
        'POST stores/{store}/appointments/{appointment}/reject' => ['appointment_rejected', 'Rejected an appointment'],
        'DELETE stores/{store}/appointments/{appointment}' => ['appointment_deleted', 'Deleted an appointment'],
        'POST catalog/{store}/book' => ['booking_submitted', 'Booked through the shop page'],
        'PUT my-appointments/{appointment}/reschedule' => ['appointment_rescheduled', 'Rescheduled their appointment'],
        'DELETE my-appointments/{appointment}' => ['appointment_cancelled', 'Cancelled their appointment'],

        // Job orders & payments
        'POST stores/{store}/jobs' => ['job_order_created', 'Created a job order'],
        'POST stores/{store}/bulk-orders' => ['bulk_order_created', 'Created a bulk order'],
        'POST stores/{store}/made-to-order' => ['made_to_order_created', 'Placed a made-to-order request'],
        'POST stores/{store}/repair-requests' => ['repair_request_created', 'Sent a repair request'],
        'PUT stores/{store}/jobs/{jobOrder}' => ['job_order_updated', 'Updated a job order'],
        'DELETE stores/{store}/jobs/{jobOrder}' => ['job_order_deleted', 'Deleted a job order'],
        'POST stores/{store}/jobs/{jobOrderId}/restore' => ['job_order_restored', 'Restored a job order'],
        'POST stores/{store}/jobs/{jobOrder}/reject' => ['job_order_rejected', 'Rejected a job order'],
        'POST stores/{store}/jobs/{jobOrder}/discount' => ['discount_applied', 'Applied a discount'],
        'POST stores/{store}/jobs/{jobOrder}/notify-customer' => ['customer_notified', 'Sent the customer an update'],
        'POST stores/{store}/jobs/{jobOrder}/progress-photos' => ['progress_photo_added', 'Added a progress photo'],
        'DELETE stores/{store}/jobs/{jobOrder}/progress-photos' => ['progress_photo_deleted', 'Removed a progress photo'],
        'POST stores/{store}/jobs/{jobOrder}/roster/{index}/toggle' => ['roster_updated', 'Updated a bulk-order roster'],
        'POST stores/{store}/jobs/{jobOrder}/pay' => ['payment_recorded', 'Recorded a payment'],
        'PUT stores/{store}/jobs/{jobOrder}/payments/{payment}' => ['payment_updated', 'Updated a payment'],
        'POST stores/{store}/jobs/{jobOrder}/payments/{payment}/verify' => ['payment_verified', 'Verified a payment'],
        'POST stores/{store}/jobs/{jobOrder}/payments/{payment}/reject' => ['payment_rejected', 'Rejected a payment'],
        'POST my-orders/{jobOrder}/payments' => ['payment_proof_sent', 'Sent proof of payment'],

        // Walk-in catalog orders
        'POST stores/{store}/catalog-orders' => ['catalog_order_created', 'Recorded a catalog order'],
        'PUT stores/{store}/catalog-orders/{order}' => ['catalog_order_updated', 'Updated a catalog order'],
        'PUT stores/{store}/catalog-orders/{order}/status' => ['catalog_order_status_changed', 'Changed a catalog order status'],
        'PUT stores/{store}/catalog-orders/{order}/verify-payment' => ['catalog_order_payment_verified', 'Checked a catalog order payment'],
        'POST stores/{store}/catalog-orders/{order}/discount' => ['catalog_order_discounted', 'Discounted a catalog order'],

        // Customer engagement
        'POST stores/{store}/reviews' => ['store_review_posted', 'Reviewed a shop'],
        'PUT stores/{store}/reviews/{review}' => ['store_review_updated', 'Updated a shop review'],
        'DELETE stores/{store}/reviews/mine' => ['store_review_deleted', 'Deleted their shop review'],
        'DELETE stores/{store}/reviews/{review}' => ['store_review_removed', 'Removed a shop review'],
        'POST stores/{store}/catalog/{catalogItem}/reviews' => ['design_review_posted', 'Reviewed a catalog design'],
        'DELETE stores/{store}/catalog-item-reviews/{review}' => ['design_review_removed', 'Removed a design review'],
        'POST stores/{store}/services/{service}/reviews' => ['service_review_posted', 'Rated a service'],
        'POST stores/{store}/service-packages/{servicePackage}/reviews' => ['package_review_posted', 'Rated a combo package'],
        'POST stores/{store}/catalog/{catalogItem}/save' => ['design_save_toggled', 'Saved or unsaved a catalog design'],
        'POST stores/{store}/services/{service}/save' => ['service_save_toggled', 'Saved or unsaved a service'],
        'POST stores/{store}/catalog/{catalogItem}/report' => ['design_reported', 'Reported a catalog design'],

        // Support
        'POST stores/{store}/tickets' => ['ticket_opened', 'Opened a support ticket'],
        'POST stores/{store}/tickets/{ticket}/reply' => ['ticket_replied', 'Replied to a support ticket'],
        'POST stores/{store}/tickets/{ticket}/close' => ['ticket_closed', 'Closed a support ticket'],
        'POST my-tickets/{ticket}/reply' => ['ticket_replied', 'Replied to a support ticket'],
        'POST admin/tickets/{ticket}/reply' => ['ticket_replied', 'Replied to a support ticket'],
        'PUT admin/tickets/{ticket}/status' => ['ticket_status_changed', 'Changed a support ticket status'],

        // System Admin (most also write their own detailed entry)
        'POST admin/subscription-plans' => ['plan_created', 'Created a subscription plan'],
        'PUT admin/subscription-plans/{subscriptionPlan}' => ['plan_updated', 'Updated a subscription plan'],
        'PUT admin/stores/{store}/approve' => ['store_approved', 'Approved a shop'],
        'PUT admin/stores/{store}/reject' => ['store_rejected', 'Rejected a shop'],
        'POST admin/catalog-items/{catalogItem}/warn' => ['listing_warned', 'Warned a shop about a design'],
    ];

    /** Not user actions: bookkeeping calls, file uploads that precede a real save, lookups. */
    private const SKIP = [
        'auth/login', 'auth/admin/login', 'auth/register', 'auth/forgot-password', 'auth/reset-password',
        'notifications', 'recently-viewed', 'catalog/{store}/{catalogItem}/view',
        'upload', 'upload-receipt', 'upload-reference-image', 'resolve-maps-link',
    ];

    public static function fromRequest(Request $request, Response $response): void
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! $response->isSuccessful()
            || $request->attributes->get(AuditLog::LOGGED_FLAG)
            || ! ($route = $request->route())) {
            return;
        }

        $uri = preg_replace('#^api/v1/#', '', $route->uri());
        foreach (self::SKIP as $skip) {
            if ($uri === $skip || str_starts_with($uri, $skip.'/') || str_ends_with($uri, '/'.$skip)) {
                return;
            }
        }

        $method = $request->method() === 'PATCH' ? 'PUT' : $request->method();
        [$action, $description] = self::ACTIONS["{$method} {$uri}"] ?? self::describe($method, $uri);

        $subject = collect($route->parameters())->filter(fn ($p) => $p instanceof Model)->last();
        $user = Auth::guard('sanctum')->user();

        self::write($request, $user instanceof User ? $user : null, $action, $description, $subject, self::storeIdFor($route->parameters(), $subject, $user));
    }

    /** Sign-in, sign-up, password reset: nobody is signed in on the request yet. */
    public static function forUser(Request $request, User $user, string $action, string $description): void
    {
        // The actor is the subject here — no need to repeat their name.
        self::write($request, $user, $action, $description, $user, self::homeStoreId($user), withName: false);
    }

    private static function write(Request $request, ?User $user, string $action, string $description, ?Model $subject, ?int $storeId, bool $withName = true): void
    {
        try {
            $name = $subject && $withName ? self::nameOf($subject) : null;
            AuditLog::create([
                'store_id' => $storeId,
                'user_id' => $user?->id,
                'action' => $action,
                'model_type' => $subject ? $subject::class : null,
                'model_id' => $subject?->getKey(),
                'payload' => array_filter([
                    'name' => $name,
                    'description' => $description.($name ? ' — '.$name : ''),
                ]),
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            // The log must never break what the person was actually doing.
            Log::warning("Activity log write failed for {$action}: {$e->getMessage()}");
        }
    }

    private static function storeIdFor(array $parameters, ?Model $subject, mixed $user): ?int
    {
        foreach ($parameters as $param) {
            if ($param instanceof Store) {
                return $param->id;
            }
        }
        if (isset($parameters['store']) && is_numeric($parameters['store'])) {
            return (int) $parameters['store'];
        }
        if ($subject && isset($subject->store_id)) {
            return (int) $subject->store_id;
        }

        // Sign-out, profile changes etc. by a shop-side account belong to their shop.
        return $user instanceof User ? self::homeStoreId($user) : null;
    }

    /** The shop a shop owner owns or a staff member works at; null for customers and admins. */
    private static function homeStoreId(User $user): ?int
    {
        return $user->stores()->value('stores.id') ?? $user->staffProfile?->store_id;
    }

    private static function nameOf(Model $model): ?string
    {
        foreach (['order_number', 'name', 'label', 'title', 'subject', 'email'] as $field) {
            if (is_string($model->getAttribute($field)) && $model->getAttribute($field) !== '') {
                return Str::limit($model->getAttribute($field), 80);
            }
        }

        return null;
    }

    /** Fallback for endpoints added later: "POST stores/{store}/fittings" → "Created fitting". */
    private static function describe(string $method, string $uri): array
    {
        $words = array_values(array_filter(explode('/', $uri), fn ($s) => ! str_starts_with($s, '{')));
        $last = end($words) ?: 'item';
        $verb = ['POST' => 'Created', 'PUT' => 'Updated', 'DELETE' => 'Deleted'][$method];
        $resource = Str::singular(str_replace('-', ' ', $last));

        return [Str::snake($resource).'_'.strtolower($verb), "{$verb} {$resource}"];
    }
}
