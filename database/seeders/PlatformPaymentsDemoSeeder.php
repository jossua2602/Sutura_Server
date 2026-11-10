<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Sample GCash payments for Admin → Payments (local / demo only): pending,
 * paid and rejected across the demo shops, some with warnings (wrong amount,
 * old date, reused reference) and a rejected one that was sent again.
 * Placeholder receipt images are generated. Safe to re-run: it replaces its
 * own rows (references starting with "DEMO").
 *
 *   php artisan db:seed --class=PlatformPaymentsDemoSeeder
 *
 * Plan / subscription data is not touched: approved rows here are history.
 */
class PlatformPaymentsDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') && ! filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('Production: sample payments skipped.');

            return;
        }

        $disk = Storage::disk(config('filesystems.private_disk', 'local'));
        $old = SubscriptionUpgradeRequest::where('payment_reference', 'like', 'DEMO%')->get();
        $disk->delete($old->pluck('payment_receipt_path')->filter()->values()->all());
        SubscriptionUpgradeRequest::whereIn('id', $old->pluck('id'))->update(['resubmission_of_id' => null]);
        SubscriptionUpgradeRequest::whereIn('id', $old->pluck('id'))->delete();

        $plans = SubscriptionPlan::pluck('id', 'slug');
        $prices = SubscriptionPlan::get()->keyBy('slug');
        // Shops without a payment already waiting (one open payment per shop).
        $stores = Store::where('status', 'approved')->whereNotNull('owner_id')
            ->whereNotIn('id', SubscriptionUpgradeRequest::where('status', 'pending')->pluck('store_id'))
            ->orderBy('id')->get();
        if ($stores->count() < 6 || $plans->isEmpty()) {
            $this->command?->warn('Not enough approved shops / plans for the sample payments.');

            return;
        }
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first();

        // [store#, type, plan, cycle, status, days ago, amount sent (null = exact), paid-at offset days, reject reason]
        $rows = [
            [0, 'upgrade', 'premium', 'monthly', 'pending', 0.2, null, 0, null],
            [1, 'renewal', 'pro', 'monthly', 'pending', 0.8, 700.00, 0, null],            // amount short
            [2, 'renewal', 'basic', 'yearly', 'pending', 1.5, null, -9, null],            // paid 9 days before sending
            [3, 'downgrade', 'pro', 'monthly', 'pending', 2.1, null, 0, null],
            [4, 'upgrade', 'pro', 'monthly', 'pending', 3.0, null, 0, null],              // reuses a rejected reference (see below)
            [5, 'renewal', 'premium', 'monthly', 'pending', 3.4, null, 0, null],
            [0, 'renewal', 'pro', 'monthly', 'approved', 6, null, 0, null],
            [1, 'upgrade', 'pro', 'monthly', 'approved', 12, null, 0, null],
            [2, 'renewal', 'basic', 'monthly', 'approved', 19, null, 0, null],
            [3, 'upgrade', 'premium', 'monthly', 'approved', 25, null, 0, null],
            [5, 'renewal', 'premium', 'yearly', 'approved', 33, null, 0, null],
            [4, 'renewal', 'basic', 'monthly', 'approved', 41, null, 0, null],
            [0, 'upgrade', 'pro', 'yearly', 'approved', 58, null, 0, null],
            [1, 'renewal', 'basic', 'monthly', 'approved', 74, null, 0, null],
            [2, 'upgrade', 'premium', 'monthly', 'rejected', 8, 1500.00, 0, 'Amount incorrect'],
            [3, 'renewal', 'pro', 'monthly', 'rejected', 15, null, 0, 'Unclear screenshot'],
            [4, 'upgrade', 'pro', 'monthly', 'rejected', 4, null, 0, 'Reference not found in GCash'],
            [5, 'renewal', 'premium', 'monthly', 'rejected', 22, null, 0, 'Reference not found in GCash'],
        ];

        $senders = ['Maria Cruz', 'Ana Bautista', 'Carlos Villanueva', 'Liza Ramos', 'Jun Dela Peña', 'Rhea Santos'];
        $made = [];
        foreach ($rows as $i => [$s, $type, $slug, $cycle, $status, $daysAgo, $sent, $paidOffset, $reason]) {
            $store = $stores[$s % $stores->count()];
            $plan = $prices[$slug] ?? null;
            if (! $plan) {
                continue;
            }
            $price = (float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly);
            $created = now()->subHours((int) round($daysAgo * 24));
            $reference = 'DEMO'.str_pad((string) (1000 + $i * 37), 9, '0', STR_PAD_LEFT);
            $path = 'subscription-upgrades/receipts/demo-'.($i + 1).'.png';
            $disk->put($path, $this->receiptPng($i));

            $reviewed = $status !== 'pending' ? $created->copy()->addHours(5) : null;
            $days = $cycle === 'yearly' ? 365 : 30;

            $payment = SubscriptionUpgradeRequest::create([
                'store_id' => $store->id,
                'type' => $type,
                'requested_by' => $store->owner_id,
                'plan_id' => $plans[$slug],
                'billing_cycle' => $cycle,
                'quoted_price' => $price,
                'amount_sent' => $sent ?? $price,
                'payment_method' => 'gcash',
                'payment_reference' => $reference,
                'reference_key' => $reference,
                'sender_name' => $senders[$s % count($senders)],
                'sender_number' => '0917'.str_pad((string) (1234567 + $i * 1111), 7, '0', STR_PAD_LEFT),
                'paid_at_reported' => $created->copy()->addDays($paidOffset)->subMinutes(20),
                'payment_receipt_path' => $path,
                'status' => $status,
                'reviewed_by' => $reviewed ? $admin?->id : null,
                'reviewed_at' => $reviewed,
                'rejection_reason' => $reason,
                'billing_period_start' => $status === 'approved' ? $reviewed : null,
                'billing_period_end' => $status === 'approved' ? $reviewed->copy()->addDays($days) : null,
            ]);
            $payment->forceFill(['created_at' => $created, 'updated_at' => $reviewed ?? $created])->saveQuietly();
            $payment->logs()->create(['user_id' => $store->owner_id, 'action' => 'submitted', 'created_at' => $created]);
            if ($reviewed) {
                $payment->logs()->create([
                    'user_id' => $admin?->id, 'action' => $status,
                    'note' => $status === 'approved' ? 'Checked in GCash: reference number, amount and sender match.' : $reason,
                    'created_at' => $reviewed,
                ]);
            }
            $made[$i] = $payment;
        }

        // Row 4 (pending) is the shop sending again after row 16 was rejected, with the same reference.
        if (isset($made[4], $made[16])) {
            $made[4]->update(['resubmission_of_id' => $made[16]->id, 'payment_reference' => $made[16]->payment_reference, 'reference_key' => $made[16]->reference_key]);
            $made[4]->logs()->where('action', 'submitted')->update(['action' => 'resubmitted', 'note' => "Sent again after payment #{$made[16]->id} was rejected."]);
        }
        // Row 6 (paid) was the resend of row 17.
        if (isset($made[6], $made[17])) {
            $made[6]->update(['resubmission_of_id' => $made[17]->id]);
        }

        $this->command?->info('Sample payments: '.count($made).' (6 pending, 8 paid, 4 rejected).');
    }

    /** A plain placeholder "receipt" (GD isn't required): a blue header bar on white, with grey lines. */
    private function receiptPng(int $seed): string
    {
        $w = 360;
        $h = 640;
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $row = "\x00";
            for ($x = 0; $x < $w; $x++) {
                if ($y < 90) {
                    $px = [0, 92, 230]; // GCash-like blue header
                } elseif ($y % 48 > 38 && $y > 130 && $y < 560 && $x > 40 && $x < ($w - 40 - (($y / 48 + $seed) % 3) * 60)) {
                    $px = [200, 200, 200]; // text lines
                } elseif ($y > 590 && $y < 600 && $x > 40 && $x < 320) {
                    $px = [0, 92, 230];
                } else {
                    $px = [255, 255, 255];
                }
                $row .= chr($px[0]).chr($px[1]).chr($px[2]);
            }
            $raw .= $row;
        }
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($raw, 6))
            .$chunk('IEND', '');
    }
}
