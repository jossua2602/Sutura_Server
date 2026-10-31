<?php

use App\Http\Controllers\Api\V1\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\StoreApplicationController as AdminStoreApplicationController;
use App\Http\Controllers\Api\V1\Admin\StoreController as AdminStoreController;
use App\Http\Controllers\Api\V1\Admin\ModerationController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\Admin\SupportTicketAdminController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CatalogInteractionController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\FileUploadController;
use App\Http\Controllers\Api\V1\JobOrderController;
use App\Http\Controllers\Api\V1\JobOrderTrackingController;
use App\Http\Controllers\Api\V1\MapLinkController;
use App\Http\Controllers\Api\V1\MeasurementController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\RecentlyViewedController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ServicePackageController;
use App\Http\Controllers\Api\V1\ServicePackageReviewController;
use App\Http\Controllers\Api\V1\ServiceReviewController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\StoreApplicationController;
use App\Http\Controllers\Api\V1\StoreApplicationUpdateController;
use App\Http\Controllers\Api\V1\StoreBranchController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StorePostController;
use App\Http\Controllers\Api\V1\StoreReviewController;
use App\Http\Controllers\Api\V1\StoreSpecialHourController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SubscriptionUpgradeRequestController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionUpgradeRequestController as AdminSubscriptionUpgradeRequestController;
use App\Http\Controllers\Api\V1\SupportTicketController;
use App\Http\Controllers\Api\V1\SystemNewsController;
use App\Http\Controllers\CatalogOrderController;
use Illuminate\Support\Facades\Route;

if (! defined('MEASUREMENT_DETAIL_ROUTE')) {
    define('MEASUREMENT_DETAIL_ROUTE', '/measurements/{measurement}');
}
if (! defined('JOB_DETAIL_ROUTE')) {
    define('JOB_DETAIL_ROUTE', '/jobs/{jobOrder}');
}
if (! defined('TICKETS_ROUTE')) {
    define('TICKETS_ROUTE', '/tickets');
}
if (! defined('TICKETS_DETAIL_ROUTE')) {
    define('TICKETS_DETAIL_ROUTE', '/tickets/{ticket}');
}
if (! defined('CUSTOMER_DETAIL_ROUTE')) {
    define('CUSTOMER_DETAIL_ROUTE', '/customers/{customer}');
}
if (! defined('STAFF_DETAIL_ROUTE')) {
    define('STAFF_DETAIL_ROUTE', '/staff/{staff}');
}

Route::prefix('v1')->group(function () {
    // Laravel's throttle middleware keys its bucket by IP alone (see
    // ThrottleRequests::resolveRequestSignature) — it does NOT factor in the
    // route by default, so these four endpoints would otherwise silently
    // share one combined 6-per-minute budget instead of 6 each. The 3rd
    // throttle argument is a key prefix; giving each route its own makes the
    // buckets genuinely independent.
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1,register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1,login')->name('login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1,forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1,reset-password');
    // "Continue with Google" (customers only): browser goes to /redirect,
    // Google returns to /callback, and the SPA trades the one-time code for
    // a token at /exchange. See GoogleAuthController.
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1,google-redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1,google-callback');
    Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:10,1,google-exchange');

    // System Admin portal sign-in — separate from the public form (which
    // refuses admin accounts) and throttled tighter. See AdminAuthController.
    Route::post('/auth/admin/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1,admin-login');

    // "Open a shop" application: creates the owner's login + a pending
    // store + the verification packet. Multipart uploads, so rate-limited.
    Route::post('/store-applications', [StoreApplicationController::class, 'store'])->middleware('throttle:5,1,store-application');
    Route::get('/public/subscription-plans', [StoreApplicationController::class, 'plans']);

    // "Request Changes": the owner (not signed in yet) updates the ticked items
    // through the private emailed link. Throttled like the original application.
    Route::get('/store-applications/update', [StoreApplicationUpdateController::class, 'show'])->middleware('throttle:30,1,store-application-update-view');
    Route::post('/store-applications/update', [StoreApplicationUpdateController::class, 'update'])->middleware('throttle:5,1,store-application-update');

    // Location picker: expands a short Google Maps link (maps.app.goo.gl) to
    // lat/lng. Public (registration is signed out); outbound fetch, so throttled.
    Route::post('/public/resolve-map-url', [MapLinkController::class, 'resolve'])->middleware('throttle:20,1,resolve-map-url');

    // Public Job Order Tracking — no account needed, just the tracking_code
    // handed to the customer at intake. Backend-only for now; no consuming
    // page yet (see the loop's memory note for the pending frontend task).
    // Throttled per IP, same reasoning as the auth routes above, though the
    // 8-char code space makes brute-forcing impractical either way.
    Route::get('/track/{trackingCode}', [JobOrderTrackingController::class, 'show'])->middleware('throttle:20,1,track');

    // Public Catalog & Booking
    Route::get('/catalog/{store:slug}', [CatalogController::class, 'index']);
    Route::get('/catalog/{store:slug}/booking-settings', [PublicBookingController::class, 'getSettings']);
    Route::get('/catalog/{store:slug}/appointments', [PublicBookingController::class, 'getAppointments']);
    // Rate limit: 10 bookings per minute per IP — high enough for a real
    // customer retrying after a validation error, low enough to slow a
    // spam bot hitting every store's /book endpoint.
    Route::post('/catalog/{store:slug}/book', [PublicBookingController::class, 'submit'])->middleware('throttle:10,1,book');
    Route::get('/catalog/{store:slug}/{catalog}', [CatalogController::class, 'show']);
    Route::post('/catalog/{store:slug}/{catalogItem}/view', [CatalogInteractionController::class, 'incrementViews']);

    Route::middleware(['auth:sanctum', 'throttle:authed'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // System News — real commit history from both repos, not a
        // hardcoded changelog (see SystemNewsController for why).
        Route::get('/system-news', [SystemNewsController::class, 'index']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/preferences', [NotificationController::class, 'getPreferences']);
        Route::post('/notifications/preferences', [NotificationController::class, 'updatePreferences']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('/notifications/bulk-read', [NotificationController::class, 'bulkRead']);
        Route::post('/notifications/bulk-delete', [NotificationController::class, 'bulkDelete']);
        Route::delete('/notifications/clear-all', [NotificationController::class, 'clearAll']);
        Route::get('/notifications/{id}', [NotificationController::class, 'show']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/{id}/unread', [NotificationController::class, 'markAsUnread']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        // Catalog Interactions (Any authenticated user) — {store:slug}, not a
        // bare {store}: every customer-facing page only ever has the store's
        // SLUG in its URL/state (see store/[store_id]/page.tsx — that param is
        // actually the slug), never its numeric id. A bare {store} binds by
        // primary key, so calling any of these with a slug 404'd outright —
        // confirmed live (curl) before this fix. toggleSave/rate/report all
        // shared this bug; fixed together since they're the same call shape.
        Route::post('/stores/{store:slug}/catalog/{catalogItem}/save', [CatalogInteractionController::class, 'toggleSave']);
        Route::get('/stores/{store:slug}/catalog/{catalogItem}/my-save', [CatalogInteractionController::class, 'mySave']);
        Route::post('/stores/{store:slug}/catalog/{catalogItem}/reviews', [CatalogInteractionController::class, 'rate']);
        Route::post('/stores/{store:slug}/catalog/{catalogItem}/report', [CatalogInteractionController::class, 'report']);

        // Service Ratings (Any authenticated user) — star-only, see ServiceReviewController.
        Route::post('/stores/{store:slug}/services/{service}/reviews', [ServiceReviewController::class, 'rate']);
        Route::get('/stores/{store:slug}/services/{service}/my-review', [ServiceReviewController::class, 'myRating']);
        // Service Saves/Hearts — parity with catalog items above, services
        // never had a save/heart equivalent before this.
        Route::post('/stores/{store:slug}/services/{service}/save', [ServiceReviewController::class, 'toggleSave']);
        Route::get('/stores/{store:slug}/services/{service}/my-save', [ServiceReviewController::class, 'mySave']);

        // Service Package Ratings — separate from reviews of included services.
        Route::post('/stores/{store:slug}/service-packages/{servicePackage}/reviews', [ServicePackageReviewController::class, 'rate']);
        Route::get('/stores/{store:slug}/service-packages/{servicePackage}/my-review', [ServicePackageReviewController::class, 'myRating']);

        // Store Interactions (Any authenticated user)
        Route::get('/stores/{store:slug}/my-review', [StoreReviewController::class, 'myRating']);
        Route::get('/stores/{store}/my-review', [StoreReviewController::class, 'myRating']);
        Route::post('/stores/{store:slug}/reviews', [StoreReviewController::class, 'store']);
        Route::post('/stores/{store}/reviews', [StoreReviewController::class, 'store']);
        Route::delete('/stores/{store:slug}/reviews/mine', [StoreReviewController::class, 'unrate']);
        Route::delete('/stores/{store}/reviews/mine', [StoreReviewController::class, 'unrate']);
        Route::post('/stores/{store:slug}/repair-requests', [JobOrderController::class, 'customerRepairRequest']);
        Route::post('/stores/{store:slug}/bulk-orders', [JobOrderController::class, 'customerBulkOrder']);
        Route::post('/stores/{store:slug}/made-to-order', [JobOrderController::class, 'customerMadeToOrder']);
        Route::post('/stores/{store:slug}/service-orders', [JobOrderController::class, 'customerServiceOrder'])->middleware('throttle:sensitive-writes');

        // My Orders / My Appointments — cross-store, for whoever is logged
        // in (Objective 5: "customers monitor their order progress from
        // placement to pickup"). No role gate: filters by the authenticated
        // user's own id as customer_id, same as /auth/me isn't role-gated.
        Route::get('/my-orders', [JobOrderTrackingController::class, 'myOrders']);
        Route::get('/my-orders/{jobOrder}', [JobOrderTrackingController::class, 'myOrderDetail']);
        Route::post('/my-orders/{jobOrder}/payments', [\App\Http\Controllers\Api\V1\CustomerPaymentController::class, 'store'])->middleware('throttle:sensitive-writes');
        Route::get('/my-appointments', [AppointmentController::class, 'myAppointments']);
        Route::get('/my-appointments/{appointment}', [AppointmentController::class, 'myAppointmentDetail']);
        // Self-service cancel — distinct from the owner/manager destroy()
        // below (no reason required, never sets rebooking_blocked). Lets a
        // customer free up their one-active-appointment-per-store slot
        // (PublicBookingController::submit()'s guard) to book a different
        // date themselves instead of asking the store to do it.
        Route::delete('/my-appointments/{appointment}', [AppointmentController::class, 'cancelMine']);
        // Move your own still-pending request to another time (e.g. after a walk-in took the slot).
        Route::put('/my-appointments/{appointment}/reschedule', [AppointmentController::class, 'rescheduleMine']);
        Route::get('/my-measurements', [MeasurementController::class, 'myMeasurements']);
        Route::get('/my-catalog-reviews', [CatalogInteractionController::class, 'myReviews']);
        Route::get('/my-store-reviews', [StoreReviewController::class, 'myReviews']);
        Route::get('/my-saved-catalog-items', [CatalogInteractionController::class, 'mySavedItems']);
        Route::get('/my-saved-services', [ServiceReviewController::class, 'mySavedServices']);
        Route::get('/my-recently-viewed', [RecentlyViewedController::class, 'index']);
        Route::post('/recently-viewed', [RecentlyViewedController::class, 'store']);
        Route::get('/my-tickets', [SupportTicketController::class, 'myTickets']);
        Route::get('/my-tickets/{ticket}', [SupportTicketController::class, 'myTicketShow']);
        Route::post('/my-tickets/{ticket}/reply', [SupportTicketController::class, 'myTicketReply']);

        // Profile Settings (Any authenticated user)
        Route::put('/profile/personal', [ProfileController::class, 'updatePersonal']);
        Route::put('/profile/text-messages', [ProfileController::class, 'updateTextMessages'])->middleware('throttle:sensitive-writes');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:sensitive-writes');
        Route::put('/profile/availability', [ProfileController::class, 'toggleAvailability']);
        Route::post('/profile/upload', [ProfileController::class, 'uploadImage'])->middleware('throttle:uploads');
        Route::get('/customers/{customer}/measurements', [CustomerController::class, 'customerMeasurements']);

        // Store Owner & Staff Routes
        Route::prefix('stores/{store}')->group(function () {

            // Shared Access (Owner, Manager, Staff)
            Route::middleware('role:store_owner,branch_manager,staff')->group(function () {
                Route::get('/staff-overview', [\App\Http\Controllers\Api\V1\StaffOverviewController::class, 'show']);
                // Support Tickets (Shop owner, branch manager and staff → System Admin; non-owners see only their own)
                Route::get(TICKETS_ROUTE, [SupportTicketController::class, 'index']);
                Route::post(TICKETS_ROUTE, [SupportTicketController::class, 'store'])->middleware('throttle:sensitive-writes');
                Route::get(TICKETS_DETAIL_ROUTE, [SupportTicketController::class, 'show']);
                Route::post('/tickets/{ticket}/reply', [SupportTicketController::class, 'reply']);
                Route::post('/tickets/{ticket}/close', [SupportTicketController::class, 'close']);
                Route::post('/support/upload', [FileUploadController::class, 'uploadSupportAttachment'])->middleware('throttle:uploads');
                // Measurements
                Route::get('/measurements', [MeasurementController::class, 'index']);
                Route::get(MEASUREMENT_DETAIL_ROUTE, [MeasurementController::class, 'show']);
                Route::post('/measurements', [MeasurementController::class, 'store']);
                Route::put(MEASUREMENT_DETAIL_ROUTE, [MeasurementController::class, 'update']);
                Route::delete(MEASUREMENT_DETAIL_ROUTE, [MeasurementController::class, 'destroy']);

                // Job Orders — staff can view and progress a job's stage/status,
                // but cannot delete it or reassign who's working on it (that's a
                // supervisory action reserved for the owner/branch manager below).
                // Staff may open a job order only from an appointment (e.g. a fitting) — see JobOrderController::store.
                Route::post('/jobs', [JobOrderController::class, 'store']);
                Route::get('/jobs', [JobOrderController::class, 'index']);
                Route::get(JOB_DETAIL_ROUTE, [JobOrderController::class, 'show']);
                Route::put(JOB_DETAIL_ROUTE, [JobOrderController::class, 'update']);
                // Staff are the ones actually at the workbench — production-evidence
                // photos are a day-to-day task, not a supervisory decision, so this
                // sits in the staff-accessible group unlike reject/pay/discount below.
                Route::post('/jobs/{jobOrder}/progress-photos', [JobOrderController::class, 'addProgressPhoto']);
                Route::delete('/jobs/{jobOrder}/progress-photos', [JobOrderController::class, 'deleteProgressPhoto']);
                // Per-piece completion on a bulk/team order's roster — same
                // "staff at the workbench" reasoning as progress photos above.
                Route::post('/jobs/{jobOrder}/roster/{index}/toggle', [JobOrderController::class, 'toggleRosterItem'])->whereNumber('index');

                // Appointments — read + status transitions (role enforcement inside controller)
                Route::get('/appointments', [AppointmentController::class, 'index']);
                Route::put('/appointments/{appointment}', [AppointmentController::class, 'update']);
                Route::post('/appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
                // Narrow operational follow-up — Staff may create a scheduled
                // return visit (consultation/fitting/adjustment/pickup) without
                // the full owner/manager booking form's fields (payment method,
                // etc.). One endpoint, usable from either the Appointments
                // dashboard or a Job's detail page (job_order_id optional,
                // used to derive the customer when supplied).
                // CUSTOMER-WORKFLOW.md §7.4, STAFF-WORKFLOW.md §17 — a
                // deliberately narrower action than store() below, not a role
                // addition to it.
                Route::post('/appointments/follow-up', [AppointmentController::class, 'createFollowUp']);

                // Ready-to-Wear Orders — front-of-house staff record walk-in sales
                // day to day; this shouldn't require the owner.
                Route::get('/catalog-orders', [CatalogOrderController::class, 'index']);
                Route::post('/catalog-orders', [CatalogOrderController::class, 'store']);
                Route::match(['put', 'patch'], '/catalog-orders/{order}', [CatalogOrderController::class, 'update']);
                Route::match(['put', 'patch'], '/catalog-orders/{order}/status', [CatalogOrderController::class, 'update']);

                // Customers CRM — front-of-house staff look up/add customers day to
                // day too; CustomerController's own authorization already permits
                // staff for every method here, so the route gate must match it.
                Route::get('/customers', [CustomerController::class, 'index']);
                Route::get(CUSTOMER_DETAIL_ROUTE, [CustomerController::class, 'show']);
                Route::get('/customers/{customer}/measurements', [CustomerController::class, 'measurements']);
                Route::post('/customers', [CustomerController::class, 'store']);
                Route::put(CUSTOMER_DETAIL_ROUTE, [CustomerController::class, 'update']);
                Route::delete(CUSTOMER_DETAIL_ROUTE, [CustomerController::class, 'destroy']);

                // Services (read-only) — every role that can create/edit an
                // appointment or job order needs to populate a service picker;
                // managing services (create/update/delete) stays owner-only below.
                Route::get('/services', [ServiceController::class, 'index']);

                // Service Packages (read-only) — this used to sit in the
                // store_owner-only group below even though its own write
                // actions (store/update/destroy) already moved to
                // store_owner,branch_manager, and even though the Services nav
                // (with its Packages tab) is shown to branch_manager AND
                // plain staff. A branch manager or staff account opening the
                // Packages tab got a 403 just listing packages, despite
                // managers being able to create/edit/delete them. Matches
                // Services' own read gate immediately above.
                Route::get('/service-packages', [ServicePackageController::class, 'index']);

                // Staff & Branch directory (read-only) — staff, managers, and owners
                // need to see the artisan roster, availability, and branch assignments.
                Route::get('/staff', [StaffController::class, 'index']);
                Route::get(STAFF_DETAIL_ROUTE, [StaffController::class, 'show']);
                Route::get('/branches', [StoreBranchController::class, 'index']);

                // Store active subscription tier (read-only for feature gating)
                Route::get('/subscription', [SubscriptionController::class, 'current']);
            });

            // Owner & Branch Manager Access
            Route::middleware('role:store_owner,branch_manager')->group(function () {
                // SMS outbox: texts wait here for the shop to check the number and wording before anything is sent
                Route::get('/sms', [\App\Http\Controllers\Api\V1\SmsController::class, 'index']);
                Route::put('/sms/settings', [\App\Http\Controllers\Api\V1\SmsController::class, 'settings']);
                Route::post('/sms/approve-all', [\App\Http\Controllers\Api\V1\SmsController::class, 'approveAll'])->middleware('throttle:sensitive-writes');
                Route::put('/sms/{message}', [\App\Http\Controllers\Api\V1\SmsController::class, 'update']);
                Route::post('/sms/{message}/approve', [\App\Http\Controllers\Api\V1\SmsController::class, 'approve'])->middleware('throttle:sensitive-writes');
                Route::post('/sms/{message}/cancel', [\App\Http\Controllers\Api\V1\SmsController::class, 'cancel']);

                // Statements: every payment record + receipt image, by period (bulk export is Premium)
                Route::get('/receipts', [\App\Http\Controllers\Api\V1\ReceiptExportController::class, 'index']);
                Route::get('/receipts/file', [\App\Http\Controllers\Api\V1\ReceiptExportController::class, 'file']);
                Route::get('/receipts/export', [\App\Http\Controllers\Api\V1\ReceiptExportController::class, 'export']);

                // Completed Orders page: completed jobs only + their summary cards (shows order value, so not staff)
                Route::get('/completed-orders', [\App\Http\Controllers\Api\V1\CompletedOrderController::class, 'index']);
                Route::get('/completed-orders/export', [\App\Http\Controllers\Api\V1\CompletedOrderController::class, 'export']);

                // Job Orders (Owner/Manager specific actions)
                Route::post('/jobs/{jobOrder}/pay', [JobOrderController::class, 'pay']);
                Route::post('/jobs/{jobOrder}/discount', [JobOrderController::class, 'applyDiscount']);
                Route::post('/jobs/{jobOrder}/payments/{payment}/reject', [JobOrderController::class, 'rejectPayment']);
                Route::post('/jobs/{jobOrder}/payments/{payment}/verify', [JobOrderController::class, 'verifyPayment']);
                Route::post('/jobs/{jobOrder}/reject', [JobOrderController::class, 'rejectOrder']);
                Route::post('/jobs/{jobOrder}/approve', [JobOrderController::class, 'approveOrder']);
                Route::put('/jobs/{jobOrder}/payments/{payment}', [JobOrderController::class, 'updatePayment']);
                Route::post('/jobs/{jobOrder}/notify-customer', [JobOrderController::class, 'notifyCustomer']);
                Route::post('/jobs/{jobOrderId}/restore', [JobOrderController::class, 'restore'])->whereNumber('jobOrderId');
                Route::delete(JOB_DETAIL_ROUTE, [JobOrderController::class, 'destroy']);

                // Catalog Orders — discount decisions are a supervisory action,
                // same as verify-payment below, unlike the day-to-day
                // create/update Staff already has above.
                Route::post('/catalog-orders/{order}/discount', [CatalogOrderController::class, 'applyDiscount']);
                Route::put('/catalog-orders/{order}/verify-payment', [CatalogOrderController::class, 'verifyPayment']);

                // Appointments — create, cancel, and payment verification
                // (owner/manager only). Payment capture/verification is
                // Owner/Branch-Manager-exclusive under the finalized target
                // (PAYMENT-WORKFLOW.md Part B) — moved here from the shared
                // Staff-accessible group above; Staff's role narrows to
                // reading the resulting payment_status, never setting it.
                Route::post('/appointments', [AppointmentController::class, 'store']);
                Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy']);
                Route::get('/decision-queue', [\App\Http\Controllers\Api\V1\DecisionQueueController::class, 'show']);
                Route::post('/appointments/{appointment}/reject', [AppointmentController::class, 'reject']);
                Route::get('/payment-methods', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'index']);
                Route::post('/payment-methods', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'store']);
                Route::put('/payment-methods/{paymentMethod}', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'update']);
                Route::delete('/payment-methods/{paymentMethod}', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'destroy']);
                Route::put('/appointments/{appointment}/assign', [AppointmentController::class, 'assign']);
                Route::put('/appointments/{appointment}/verify-payment', [AppointmentController::class, 'verifyPayment']);

                // Analytics
                Route::get('/analytics', [AnalyticsController::class, 'index']);

                // File Uploads
                Route::post('/upload', [FileUploadController::class, 'store'])->middleware('throttle:uploads');

                // Staff Management (list/read is granted to all store members
                // above) — a branch manager can hire/edit/deactivate staff too,
                // routine headcount for their own branch, but
                // StaffController::staffManagerCrudDenied() enforces the real
                // scoping (their own branch only, never another branch
                // manager, never a promotion to branch manager) since role
                // middleware alone can't express "only your own branch."
                Route::post('/staff', [StaffController::class, 'store']);
                Route::put(STAFF_DETAIL_ROUTE, [StaffController::class, 'update']);
                Route::delete(STAFF_DETAIL_ROUTE, [StaffController::class, 'destroy']);

                // Services + Catalog Management write actions — the dashboard
                // sidebar has always shown "Services" and "Catalog Designs" to
                // both store_owner and branch_manager (isStoreOwner ||
                // isBranchManager gates in dashboard/layout.tsx), but these
                // write routes were store_owner-only until now, so a branch
                // manager clicking Add Service/Add Catalog Item got a 403 the
                // nav never warned them about. Moved here to match what the
                // nav already promises — these are store-wide resources (no
                // store_branch_id column), not scoped to the manager's own
                // branch the way Staff/Jobs/Appointments are above.
                Route::post('/services', [ServiceController::class, 'store']);
                Route::put('/services/{service}', [ServiceController::class, 'update']);
                Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

                // Ratings lists for the Services / Catalog Analytics tabs, which branch managers see too.
                Route::get('/service-reviews', [ServiceReviewController::class, 'indexForStore']);
                Route::get('/catalog-item-reviews', [CatalogInteractionController::class, 'indexForStore']);
                Route::post('/service-packages', [ServicePackageController::class, 'store']);
                Route::put('/service-packages/{servicePackage}', [ServicePackageController::class, 'update']);
                Route::delete('/service-packages/{servicePackage}', [ServicePackageController::class, 'destroy']);

                // Read moved here from the store_owner-only group below —
                // Catalog Designs is shown to isStoreOwner||isBranchManager
                // in dashboard/layout.tsx, and a branch manager already has
                // write access (store/update/destroy right here), but the
                // list itself was left owner-only, so a branch manager's
                // Catalog page 403'd just loading the item list they were
                // otherwise fully permitted to manage.
                // GET /catalog (list) and GET /catalog/{catalog} are served by the public routes at the
                // bottom of this file: CatalogController itself shows owners/staff of THIS store the full
                // data and everyone else active items only. A second GET here would be silently
                // overridden by that one (same method + URI), so it is not repeated.
                Route::post('/catalog', [CatalogController::class, 'store']);
                Route::put('/catalog/{catalog}', [CatalogController::class, 'update']);
                Route::delete('/catalog/{catalog}', [CatalogController::class, 'destroy']);

                // Branch Management — editing an existing branch's own info
                // (hours, contact, location). StoreBranchController::
                // branchManagerCrudDenied() enforces "their own branch only,
                // never touching manager_id" since role middleware alone
                // can't express that; store()/setMain()/destroy() stay
                // owner-only above (bigger structural decisions).
                Route::put('/branches/{branch}', [StoreBranchController::class, 'update']);
                Route::post('/branches/resolve-maps-link', [StoreBranchController::class, 'resolveMapsLink'])->middleware('throttle:sensitive-writes');
            });

            // Owner Only Access
            Route::middleware('role:store_owner')->group(function () {
                // Temporary Special Hours & Announcements
                Route::get('/special-hours', [StoreSpecialHourController::class, 'index']);
                Route::post('/special-hours', [StoreSpecialHourController::class, 'store']);
                Route::put('/special-hours/{specialHour}', [StoreSpecialHourController::class, 'update']);
                Route::delete('/special-hours/{specialHour}', [StoreSpecialHourController::class, 'destroy']);

                // Audit Logs
                Route::get('/audit-logs', [AuditLogController::class, 'index']);

                // Cross-branch performance comparison (owner-level strategic view)
                Route::get('/analytics/branches', [AnalyticsController::class, 'branchComparison']);

                // Individual staff productivity (owner-level strategic view)
                Route::get('/analytics/staff', [AnalyticsController::class, 'staffProductivity']);

                // Subscription activity — Objective 7 ("subscription activity"
                // reporting). Owner-only, matching subscribe()'s own gate —
                // billing isn't a branch-manager action anywhere else either.
                Route::get('/analytics/subscription', [AnalyticsController::class, 'subscriptionActivity']);

                // Reviews Management
                Route::get('/reviews', [StoreReviewController::class, 'index']);
                Route::put('/reviews/{review}', [StoreReviewController::class, 'update']);
                Route::delete('/reviews/{review}', [StoreReviewController::class, 'destroy']);

                // Catalog Item Reviews — per-design-item reviews (e.g. a
                // specific Barong/gown in the Design Catalog), distinct from
                // the store-level reviews above.
                Route::delete('/catalog-item-reviews/{review}', [CatalogInteractionController::class, 'destroyReview']);

                // Store Posts — completed-work showcase the owner posts to their storefront
                Route::get('/posts', [StorePostController::class, 'index']);
                Route::post('/posts', [StorePostController::class, 'store']);
                Route::put('/posts/{post}', [StorePostController::class, 'update']);
                Route::delete('/posts/{post}', [StorePostController::class, 'destroy']);

            });
        });

        // Store Management (Owner Only)
        Route::middleware('role:store_owner')->group(function () {
            Route::apiResource('stores', StoreController::class);

            // Branch Management (list/read is granted to store_owner+branch_manager
            // above). Adding/deleting a whole physical location and designating
            // the main branch stay owner-only — see branchManagerCrudDenied()'s
            // own docblock for why update()/resolveMapsLink() moved out to the
            // store_owner,branch_manager group below instead.
            Route::post('/stores/{store}/branches', [StoreBranchController::class, 'store']);
            Route::put('/stores/{store}/branches/{branch}/set-main', [StoreBranchController::class, 'setMain']);
            Route::delete('/stores/{store}/branches/{branch}', [StoreBranchController::class, 'destroy']);

            // Subscription Plan Billing
            Route::get('/subscriptions/plans', [SubscriptionController::class, 'index']);
            Route::post('/stores/{store}/subscription', [SubscriptionController::class, 'subscribe']);
            // Paid plan changes: GCash receipt → admin approval (see SubscriptionUpgradeRequestController).
            Route::get('/stores/{store}/subscription/upgrade-requests', [SubscriptionUpgradeRequestController::class, 'index']);
            Route::post('/stores/{store}/subscription/upgrade-requests', [SubscriptionUpgradeRequestController::class, 'store']);
            Route::get('/stores/{store}/subscription/upgrade-requests/{upgradeRequest}/receipt', [SubscriptionUpgradeRequestController::class, 'receipt']);
            Route::put('/stores/{store}', [StoreController::class, 'update']);
        });

        // Admin Routes
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'overview']);
            Route::get('/notifications', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'index']);
            Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'markAllRead']);
            Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\V1\Admin\NotificationController::class, 'markRead']);
            // Branch map-location checks (a new or moved branch stays off the public map until verified)
            Route::get('/branches', [\App\Http\Controllers\Api\V1\Admin\BranchVerificationController::class, 'index']);
            Route::put('/branches/{branch}/verify', [\App\Http\Controllers\Api\V1\Admin\BranchVerificationController::class, 'verify']);
            Route::put('/branches/{branch}/reject', [\App\Http\Controllers\Api\V1\Admin\BranchVerificationController::class, 'reject']);
            // Typed-in apparel names wait here before designs using them go public
            Route::get('/apparel-types', [\App\Http\Controllers\Api\V1\Admin\ApparelTypeController::class, 'index']);
            Route::put('/apparel-types/{apparelType}/approve', [\App\Http\Controllers\Api\V1\Admin\ApparelTypeController::class, 'approve']);
            Route::put('/apparel-types/{apparelType}/reject', [\App\Http\Controllers\Api\V1\Admin\ApparelTypeController::class, 'reject']);
            Route::get('/reports/subscriptions', [AdminDashboardController::class, 'subscriptionReport']);
            Route::get('/reports/subscriptions/plans/{plan}/stores', [AdminDashboardController::class, 'planStores']);
            Route::get('/reports/revenue', [AdminDashboardController::class, 'revenueReport']);
            Route::get('/upgrade-requests', [AdminSubscriptionUpgradeRequestController::class, 'index']);
            Route::get('/upgrade-requests/{upgradeRequest}/receipt', [AdminSubscriptionUpgradeRequestController::class, 'receipt']);
            Route::post('/upgrade-requests/{upgradeRequest}/approve', [AdminSubscriptionUpgradeRequestController::class, 'approve']);
            Route::post('/upgrade-requests/{upgradeRequest}/reject', [AdminSubscriptionUpgradeRequestController::class, 'reject']);

            // Shop application review queue (documents stream from the
            // private disk through this controller only).
            Route::get('/store-applications', [AdminStoreApplicationController::class, 'index']);
            Route::get('/store-applications/{store}', [AdminStoreApplicationController::class, 'show']);
            Route::get('/store-applications/{store}/documents/{document}', [AdminStoreApplicationController::class, 'document'])
                ->where('document', '[a-z-]+[0-9]*');
            Route::put('/store-applications/{store}/approve', [AdminStoreApplicationController::class, 'approve']);
            Route::put('/store-applications/{store}/reject', [AdminStoreApplicationController::class, 'reject']);
            Route::put('/store-applications/{store}/request-changes', [AdminStoreApplicationController::class, 'requestChanges']);

            Route::get('/accounts', [AdminAccountController::class, 'index']);
            Route::put('/accounts/{user}/suspend', [AdminAccountController::class, 'suspend']);
            Route::put('/accounts/{user}/reactivate', [AdminAccountController::class, 'reactivate']);

            Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);

            Route::get('/stores', [AdminStoreController::class, 'index']);
            Route::put('/stores/{store}/approve', [AdminStoreController::class, 'approve']);
            Route::put('/stores/{store}/reject', [AdminStoreController::class, 'reject']);

            // Post-moderation (acts on "Report this product" tickets): warn →
            // hide one catalog design → hide the whole shop. Manual only.
            Route::post('/catalog-items/{catalogItem}/warn', [ModerationController::class, 'warnCatalogItem']);
            Route::put('/catalog-items/{catalogItem}/hide', [ModerationController::class, 'hideCatalogItem']);
            Route::put('/catalog-items/{catalogItem}/unhide', [ModerationController::class, 'unhideCatalogItem']);
            Route::put('/stores/{store}/hide', [ModerationController::class, 'hideStore']);
            Route::put('/stores/{store}/unhide', [ModerationController::class, 'unhideStore']);

            Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
            Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
            Route::put('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'update']);

            // Admin Support Ticket Management
            Route::get(TICKETS_ROUTE, [SupportTicketAdminController::class, 'index']);
            Route::get(TICKETS_DETAIL_ROUTE, [SupportTicketAdminController::class, 'show']);
            Route::post('/tickets/{ticket}/reply', [SupportTicketAdminController::class, 'reply']);
            Route::put('/tickets/{ticket}/status', [SupportTicketAdminController::class, 'updateStatus']);
        });
    });

    // Public Catalog & Store Profile
    // Store Discovery — search/filter/map feed (Objectives 3 & 4)
    Route::get('/public/stores', [StoreController::class, 'publicIndex']);
    // Minimal public profile (name/avatar only) — used when a customer taps
    // another reviewer's name/avatar on a catalog item's Ratings & Reviews
    // page. Own-account viewing bypasses this entirely on the frontend and
    // links straight to /account instead.
    Route::get('/public/users/{id}', [ProfileController::class, 'publicShow']);
    // Cross-store catalog showroom feed for the landing page's catalog grid
    Route::get('/public/catalog-items', [CatalogController::class, 'publicShowroom']);
    Route::get('/public/services', [ServiceController::class, 'publicShowroom']);
    Route::get('/public/service-packages', [ServicePackageController::class, 'publicShowroom']);
    Route::get('/public/stores/{store:slug}', [StoreController::class, 'publicProfile']);
    Route::get('/public/stores/{store:slug}/services', [ServiceController::class, 'publicIndex']);
    Route::get('/public/stores/{store:slug}/payment-methods', [\App\Http\Controllers\Api\V1\PaymentMethodController::class, 'publicIndex']);
    Route::get('/public/stores/{store:slug}/service-packages', [ServicePackageController::class, 'publicIndex']);
    Route::get('/public/stores/{store:slug}/service-packages/{servicePackage}/reviews', [ServicePackageReviewController::class, 'publicIndex']);
    Route::get('/public/stores/{store:slug}/posts', [StorePostController::class, 'publicIndex']);
    Route::get('/public/stores/{store:slug}/reviews', [StoreReviewController::class, 'publicIndex']);
    Route::post('/public/stores/{store:slug}/upload-receipt', [FileUploadController::class, 'uploadPublicReceipt'])->middleware('throttle:uploads');
    Route::post('/public/stores/{store:slug}/upload-reference-image', [FileUploadController::class, 'uploadPublicReferenceImage'])->middleware('throttle:uploads');
    Route::get('/stores/{store}/catalog', [CatalogController::class, 'index']);
    Route::get('/stores/{store}/catalog/{catalog}', [CatalogController::class, 'show']);
});
