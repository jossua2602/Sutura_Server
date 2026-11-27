<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\CatalogOrder;
use App\Models\JobOrder;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Adds what the bell needs to show a notification at a glance, worked out when
 * it's read (so older notifications get it too):
 *
 * - sender:  who it's from or about, with their picture when they have one.
 *            Shop side: the customer on the order or appointment.
 *            Customer side: the shop (staff stay private to customers).
 *            Platform news (approval, billing, map checks): SUTURA.
 * - summary: one short, plain sentence about what happened.
 * - context: the details to scan (order number, what's being made, amount, time).
 *
 * Records are loaded in one query per kind for the whole page of notifications.
 */
class NotificationPresenter
{
    /** Sent by SUTURA itself (admin decisions, plan billing). */
    private const PLATFORM_PREFIXES = ['store_', 'plan_', 'subscription_', 'branch_', 'apparel_type_', 'listing_'];

    /** Daily digests about the shop's own records. */
    private const DIGESTS = ['overdue_jobs_digest', 'unclaimed_pickups_digest', 'jobs_on_hold_digest'];

    /**
     * @param  iterable<\Illuminate\Notifications\DatabaseNotification>  $notifications
     * @return array<int, array<string, mixed>>
     */
    public static function present(iterable $notifications): array
    {
        $rows = collect($notifications)->map(fn ($n) => [
            'model' => $n,
            'data' => is_string($n->data) ? (json_decode($n->data, true) ?: []) : ($n->data ?? []),
        ]);

        $jobs = self::load(JobOrder::class, $rows, 'job_order_id',
            ['customer:id,name,profile_picture', 'service:id,name', 'catalogItem:id,name', 'servicePackage:id,name', 'store:id,name,logo_path']);
        $appointments = self::load(Appointment::class, $rows, 'appointment_id',
            ['customer:id,name,profile_picture', 'service:id,name', 'catalogItem:id,name', 'servicePackage:id,name', 'store:id,name,logo_path']);
        $catalogOrders = self::load(CatalogOrder::class, $rows, 'catalog_order_id',
            ['customer:id,name,profile_picture', 'catalogItem:id,name', 'store:id,name,logo_path']);
        $stores = self::load(Store::class, $rows, 'store_id', []);

        return $rows->map(function (array $row) use ($jobs, $appointments, $catalogOrders, $stores) {
            $n = $row['model'];
            $data = $row['data'];
            $job = $jobs->get($data['job_order_id'] ?? null);
            $appt = $appointments->get($data['appointment_id'] ?? null);
            $catalogOrder = $catalogOrders->get($data['catalog_order_id'] ?? null);

            return [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $data,
                'sender' => self::sender($data, $job, $appt, $catalogOrder, $stores->get($data['store_id'] ?? null)),
                'summary' => self::summary($data, $job, $appt),
                'context' => self::context($data, $job, $appt, $catalogOrder),
                'read_at' => $n->read_at ? (is_string($n->read_at) ? $n->read_at : $n->read_at->toISOString()) : null,
                'created_at' => is_string($n->created_at) ? $n->created_at : $n->created_at?->toISOString(),
            ];
        })->values()->all();
    }

    /** @return Collection<int, \Illuminate\Database\Eloquent\Model> keyed by id */
    private static function load(string $model, Collection $rows, string $key, array $with): Collection
    {
        $ids = $rows->pluck("data.$key")->filter(fn ($id) => is_numeric($id))->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return $model::query()->with($with)->whereIn('id', $ids)->get()->keyBy('id');
    }

    /** A notification addressed to a customer (it carries the shop, or links into /account). */
    private static function forCustomer(array $data): bool
    {
        $url = (string) ($data['action_url'] ?? '');

        return array_key_exists('store', $data) || str_starts_with($url, '/account') || str_starts_with($url, '/track');
    }

    private static function sender(array $data, ?JobOrder $job, ?Appointment $appt, ?CatalogOrder $catalogOrder, ?Store $store): array
    {
        $type = (string) ($data['type'] ?? '');

        if (self::forCustomer($data)) {
            $shop = $job?->store ?? $appt?->store ?? $catalogOrder?->store;
            $name = $shop?->name ?? ($data['store']['name'] ?? null);
            if ($name) {
                return ['kind' => 'shop', 'name' => $name, 'avatar' => $shop?->logo_path ?? ($data['store']['logo_path'] ?? null)];
            }
        }

        foreach (self::PLATFORM_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return ['kind' => 'platform', 'name' => 'SUTURA', 'avatar' => null];
            }
        }

        if (in_array($type, self::DIGESTS, true) && $store) {
            return ['kind' => 'shop', 'name' => $store->name, 'avatar' => $store->logo_path];
        }

        $customer = $job?->customer ?? $appt?->customer ?? $catalogOrder?->customer;
        if ($customer) {
            return ['kind' => 'customer', 'name' => $customer->name, 'avatar' => $customer->profile_picture];
        }

        $name = $data['customer_name'] ?? $data['sender_name'] ?? $data['staff_name'] ?? $data['store_name'] ?? null;

        return ['kind' => 'other', 'name' => $name, 'avatar' => null];
    }

    /** One plain sentence, written to sit under the sender's name. Null: the client shows the stored title. */
    private static function summary(array $data, ?JobOrder $job, ?Appointment $appt): ?string
    {
        if (self::forCustomer($data)) {
            return null;
        }

        $type = (string) ($data['type'] ?? '');
        $visit = $appt ? self::visitLabel($appt) : 'appointment';

        return match ($type) {
            'payment_received' => ! empty($data['pending_verification'])
                ? 'Sent a payment. Check the receipt before it counts.'
                : 'Made a payment.',
            'payment_rejected' => 'A payment was turned down'.(! empty($data['rejected_by']) ? ' by '.$data['rejected_by'] : '').'.',
            'new_job_order' => 'New order'.(! empty($data['branch_name']) ? ' at '.$data['branch_name'] : '').'.',
            'job_new_for_branch' => 'New order at your branch.',
            'job_order_assigned' => 'Their order is now in your queue'.(! empty($data['sender_name']) ? ', from '.$data['sender_name'] : '').'.',
            'new_catalog_order' => 'Ordered a ready-to-wear piece.',
            'appointment_booked' => "Booked a {$visit}.",
            'appointment_assigned' => "Their {$visit} is assigned to you.",
            'appointment_cancelled' => "Cancelled their {$visit}.",
            'appointment_rescheduled' => "Moved their {$visit} to a new time.",
            'claim_filed' => 'Reported a problem with their order.',
            'claim_offer_accepted' => 'Accepted the free repair.',
            'job_ready_for_pickup' => 'Their order is ready for pickup.',
            'job_completed' => 'Their order is done and picked up.',
            default => str_starts_with($type, 'job_') && $job && ! empty($data['message'])
                // "ORD-0017 (Name) moved to production." → "Moved to production."
                ? self::stageSentence((string) $data['message'])
                : null,
        };
    }

    /** "ORD-0017 (Clareynz Masudog) moved to production." → "Order moved to production." */
    private static function stageSentence(string $message): ?string
    {
        if (preg_match('/moved to (.+?)\.?$/i', $message, $m)) {
            return 'Their order moved to '.str_replace('_', ' ', $m[1]).'.';
        }

        return null;
    }

    /** @return string[] short details, most useful first */
    private static function context(array $data, ?JobOrder $job, ?Appointment $appt, ?CatalogOrder $catalogOrder): array
    {
        $parts = [];

        if ($orderNo = $data['order_number'] ?? $job?->order_number) {
            $parts[] = $orderNo;
        }
        $made = $job?->servicePackage?->name ?? $job?->service?->name ?? $job?->catalogItem?->name ?? $job?->garment_category
            ?? $catalogOrder?->catalogItem?->name ?? ($data['item_name'] ?? null);
        if ($made) {
            $parts[] = $made;
        }

        if ($appt) {
            // The kind of visit is already in the summary ("Booked a fitting."); add what it's for.
            if ($what = $appt->servicePackage?->name ?? $appt->service?->name ?? $appt->catalogItem?->name) {
                $parts[] = $what;
            }
            if ($appt->scheduled_at) {
                $parts[] = Carbon::parse($appt->scheduled_at)->format('M j, g:i A');
            }
        }

        $amount = $data['amount'] ?? null;
        if (is_numeric($amount) && (float) $amount > 0) {
            $parts[] = '₱'.number_format((float) $amount, 2);
        }

        return array_values(array_unique(array_filter($parts, fn ($p) => is_string($p) && $p !== '')));
    }

    /** "fitting", "consultation", or the shop's own purpose label. */
    private static function visitLabel(Appointment $appt): string
    {
        if ($appt->appointment_type === 'other' && $appt->purpose_label) {
            return mb_strtolower($appt->purpose_label);
        }

        return str_replace('_', ' ', (string) ($appt->appointment_type ?: 'appointment'));
    }
}
