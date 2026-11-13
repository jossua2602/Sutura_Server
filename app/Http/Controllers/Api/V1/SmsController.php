<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Models\Store;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplates;
use App\Support\PhoneNumber;
use App\Support\PlanGate;
use App\Support\SmsText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shop's SMS outbox: texts drafted by the system wait here so the owner or branch manager can check the number
 * and the wording, fix them, then approve. Nothing is sent from this controller without that approval.
 */
class SmsController extends Controller
{
    private const PROBLEMS = ['blocked', 'failed'];

    private function scoped(Request $request, Store $store)
    {
        $q = SmsMessage::where('store_id', $store->id);
        $branch = $request->user()->hasRole('store_owner') ? null : $request->user()->staffProfile?->store_branch_id;
        if ($branch) {
            $q->where(function ($w) use ($branch) {
                $w->where(fn ($a) => $a->where('related_type', 'appointment')->whereIn('related_id', \App\Models\Appointment::where('store_branch_id', $branch)->select('id')))
                    ->orWhere(fn ($j) => $j->where('related_type', 'job_order')->whereIn('related_id', \App\Models\JobOrder::where('store_branch_id', $branch)->select('id')));
            });
        }

        return $q;
    }

    private function present(SmsMessage $m): array
    {
        $measure = SmsText::measure($m->body);

        return [
            'id' => $m->id, 'event' => $m->event, 'event_label' => SmsTemplates::EVENTS[$m->event] ?? \App\Services\Sms\PlatformSms::EVENTS[$m->event] ?? $m->event, 'status' => $m->status,
            'customer' => $m->recipient?->name, 'to_number' => $m->to_number, 'raw_number' => $m->raw_number, 'blocked_reason' => $m->blocked_reason,
            'body' => $m->body, 'chars' => $measure['chars'], 'segments' => $measure['segments'], 'is_test' => $m->is_test,
            'error' => $m->error, 'created_at' => $m->created_at, 'sent_at' => $m->sent_at, 'related_type' => $m->related_type, 'related_id' => $m->related_id,
        ];
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        $status = $request->input('status', 'draft');
        $q = $this->scoped($request, $store);
        $counts = [
            'draft' => (clone $q)->where('status', 'draft')->count(),
            'sent' => (clone $q)->where('status', 'sent')->count(),
            'problems' => (clone $q)->whereIn('status', self::PROBLEMS)->count(),
        ];
        $list = (clone $q)->with('recipient:id,name')->when($status === 'problems', fn ($w) => $w->whereIn('status', self::PROBLEMS))
            ->when(in_array($status, ['draft', 'sent', 'cancelled'], true), fn ($w) => $w->where('status', $status))
            ->latest('id')->limit(200)->get();

        return response()->json(['success' => true, 'data' => [
            'mode' => $store->sms_mode,
            'events' => collect(SmsTemplates::EVENTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'enabled' => in_array($key, SmsTemplates::enabledFor($store->sms_events), true), 'recommended' => in_array($key, SmsTemplates::DEFAULT_ENABLED, true)])->values(),
            'test_mode' => SmsSender::isTestMode(),
            'plan_allows' => PlanGate::allows($store, 'pro'),
            'daily_cap' => (int) config('sms.daily_cap'),
            'counts' => $counts,
            'messages' => $list->map(fn ($m) => $this->present($m))->values(),
        ]]);
    }

    public function settings(Request $request, Store $store): JsonResponse
    {
        abort_unless($request->user()->hasRole('store_owner'), 403, 'Only the shop owner can change how texts are sent.');
        $data = $request->validate([
            'sms_mode' => ['sometimes', 'in:off,review,auto'],
            'sms_events' => ['sometimes', 'array'],
            'sms_events.*' => ['string', 'in:'.implode(',', array_keys(SmsTemplates::EVENTS))],
        ]);
        $store->update($data);

        return response()->json(['success' => true, 'data' => ['mode' => $store->sms_mode, 'events' => SmsTemplates::enabledFor($store->sms_events)]]);
    }

    public function update(Request $request, Store $store, SmsMessage $message): JsonResponse
    {
        $message = $this->find($request, $store, $message);
        abort_unless(in_array($message->status, ['draft', 'blocked', 'failed'], true), 422, 'This message was already sent or cancelled.');
        $data = $request->validate([
            'body' => ['sometimes', 'string', 'min:5', 'max:320'],
            'number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'update_customer_number' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('body', $data)) {
            $clean = SmsText::clean($data['body']);
            abort_if(strlen($clean) < 5, 422, 'The message is empty after removing unsupported characters.');
            $message->body = $clean;
            $message->segments = SmsText::measure($clean)['segments'];
        }
        if (array_key_exists('number', $data)) {
            $normalized = PhoneNumber::normalize($data['number']);
            abort_if($normalized === null, 422, 'That is not a valid Philippine mobile number (09XX XXX XXXX).');
            $message->to_number = $normalized;
            $message->raw_number = $data['number'];
            if (($data['update_customer_number'] ?? false) && $message->recipient) {
                $message->recipient->update(['phone' => $normalized]);
            }
        }
        // A message that was stuck on a bad number becomes a normal draft once it has a good one.
        if ($message->status !== 'draft' && $message->to_number && ! $message->recipient?->sms_opt_out) {
            $message->status = 'draft';
            $message->blocked_reason = null;
            $message->error = null;
        }
        $message->save();

        return response()->json(['success' => true, 'data' => $this->present($message->load('recipient:id,name'))]);
    }

    public function approve(Request $request, Store $store, SmsMessage $message): JsonResponse
    {
        $message = $this->find($request, $store, $message);
        abort_unless(in_array($message->status, ['draft', 'failed'], true), 422, 'Only a draft (or a failed message) can be approved.');
        $message->forceFill(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();
        $sent = SmsSender::send($message);

        return response()->json(['success' => true, 'data' => $this->present($sent->load('recipient:id,name'))]);
    }

    public function approveAll(Request $request, Store $store): JsonResponse
    {
        $drafts = $this->scoped($request, $store)->where('status', 'draft')->whereNotNull('to_number')->limit(100)->get();
        $result = ['sent' => 0, 'problems' => 0];
        foreach ($drafts as $m) {
            $m->forceFill(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();
            SmsSender::send($m)->status === 'sent' ? $result['sent']++ : $result['problems']++;
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function cancel(Request $request, Store $store, SmsMessage $message): JsonResponse
    {
        $message = $this->find($request, $store, $message);
        abort_unless(in_array($message->status, ['draft', 'blocked', 'failed'], true), 422, 'Only an unsent message can be cancelled.');
        $message->update(['status' => 'cancelled']);

        return response()->json(['success' => true]);
    }

    private function find(Request $request, Store $store, SmsMessage $message): SmsMessage
    {
        return $this->scoped($request, $store)->with('recipient')->findOrFail($message->id);
    }
}
