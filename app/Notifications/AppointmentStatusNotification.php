<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Appointment $appointment;

    public string $statusType; // 'confirmed', 'rescheduled', 'cancelled', 'completed', 'walk_in_preempted'

    public ?string $customMessage;

    // Which staff/owner made this change — lets the customer-facing
    // notification row show the actual person's avatar instead of always
    // falling back to the store logo. Optional since not every call site
    // (e.g. a system-triggered status change) has a human actor.
    public ?User $actor;

    public function __construct(Appointment $appointment, string $statusType, ?string $customMessage = null, ?User $actor = null)
    {
        $this->appointment = $appointment;
        $this->statusType = $statusType;
        $this->customMessage = $customMessage;
        $this->actor = $actor;
    }

    /**
     * Delivery channels — in-app/database only for now. This used to also
     * mail the customer on every status change, but per-appointment email
     * for every pending/confirmed/rescheduled/etc. update was too noisy in
     * practice (real inbox spam during testing) for how minor most of these
     * are — the in-app bell is the one that actually matters here. toMail()
     * below is left in place, unused, in case email comes back for specific
     * statuses later rather than all of them.
     */
    public function via(object $notifiable): array
    {
        // In-app always; email too whenever the customer has a real address (walk-ins get a
        // placeholder "walkin_…" login that cannot receive mail).
        $channels = ['database'];
        if ($notifiable->hasMailableEmail()) {
            $channels[] = 'mail';
        }
        // A text only for the moments a customer must act on (the outbox decides whether it waits for review).
        if (SmsTemplates::appointment($this->appointment, $this->statusType)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toSms(object $notifiable): ?array
    {
        $text = SmsTemplates::appointment($this->appointment, $this->statusType);

        return $text ? $text + ['store_id' => $this->appointment->store_id, 'related_type' => 'appointment', 'related_id' => $this->appointment->id] : null;
    }

    private function titles(): array
    {
        return [
            'submitted' => 'Appointment Request Sent',
            'confirmed' => 'Appointment Confirmed',
            'rescheduled' => 'Appointment Rescheduled',
            'cancelled' => 'Appointment Cancelled',
            'rejected' => 'Appointment Rejected',
            'completed' => 'Appointment Completed',
            'in_progress' => 'Appointment In Progress',
            'no_show' => 'No-Show Recorded',
            'walk_in_preempted' => 'Appointment Slot Claimed by Walk-in Client',
            'shared' => 'The Store Shared Something With You',
        ];
    }

    // Every message below names both the WHEN (scheduledAt) and the WHY
    // (purpose) — a customer who forgets what they booked should be able to
    // tell from the notification alone, without having to reopen the app.
    private function messages(): array
    {
        if ($this->customMessage) {
            return [
                $this->statusType => $this->customMessage,
            ];
        }

        $scheduledAt = $this->appointment->scheduled_at
            ? Carbon::parse($this->appointment->scheduled_at)->format('M d, Y h:i A')
            : 'N/A';

        $purpose = $this->appointment->appointment_type === 'other' && $this->appointment->purpose_label
            ? strtolower($this->appointment->purpose_label)
            : ($this->appointment->appointment_type ? str_replace('_', ' ', $this->appointment->appointment_type) : 'store');

        $storeName = $this->appointment->store?->name ?? 'the store';

        return [
            'submitted' => 'Your '.$purpose.' appointment request with '.$storeName.' for '.$scheduledAt.' has been sent. You\'ll be notified once the store responds.',
            'confirmed' => 'Your '.$purpose.' appointment with '.$storeName.' for '.$scheduledAt.' has been confirmed by the store.',
            'rescheduled' => 'Your '.$purpose.' appointment with '.$storeName.' has been rescheduled to '.$scheduledAt.'.',
            'cancelled' => 'Your '.$purpose.' appointment with '.$storeName.' for '.$scheduledAt.' has been cancelled.',
            'rejected' => 'Your '.$purpose.' appointment request with '.$storeName.' for '.$scheduledAt.' was not accepted.'.($this->appointment->rejection_reason_code ? ' Reason: '.(Appointment::REJECTION_REASONS[$this->appointment->rejection_reason_code] ?? 'Other').($this->appointment->rejection_note ? ' — '.$this->appointment->rejection_note : '').'.' : ''),
            'completed' => 'Your '.$purpose.' appointment with '.$storeName.' on '.$scheduledAt.' is now marked as completed.',
            'in_progress' => 'Your '.$purpose.' appointment with '.$storeName.' is now in progress.',
            'no_show' => 'You were marked as a no-show for your '.$purpose.' appointment with '.$storeName.' at '.$scheduledAt.'.',
            'walk_in_preempted' => 'Your requested '.$purpose.' appointment slot with '.$storeName.' for '.$scheduledAt.' was claimed by an in-store walk-in client who arrived earlier. Please choose an alternative time slot.',
        ];
    }

    /**
     * Mail representation — reuses the same title/message copy as the in-app
     * notification so both channels stay in sync automatically.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->titles()[$this->statusType] ?? 'Appointment Update';
        $message = $this->messages()[$this->statusType] ?? 'Your appointment status has been updated.';

        $store = $this->appointment->store;
        $storeUrl = $store?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000').'/store/'.$store->slug) : null;

        $mail = (new MailMessage)
            ->subject($title.' — '.($store?->name ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line($message);

        if ($storeUrl) {
            $mail->action('Visit '.$store->name, $storeUrl);
        }

        return $mail->line('Thank you for booking with us!');
    }

    public function toArray(object $notifiable): array
    {
        $titles = $this->titles();
        $messages = $this->messages();
        $store = $this->appointment->store;

        return [
            'type' => 'appointment_'.$this->statusType,
            'title' => $titles[$this->statusType] ?? 'Appointment Update',
            'message' => $messages[$this->statusType] ?? 'Your appointment status has been updated.',
            // Was '/dashboard/appointments' — the store owner's own dashboard
            // route, unreachable (and meaningless) for the customer this
            // notification actually goes to.
            'action_url' => '/account/appointments/'.$this->appointment->id,
            'appointment_id' => $this->appointment->id,
            'scheduled_at' => $this->appointment->scheduled_at,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
            'actor' => $this->actor ? [
                'id' => $this->actor->id, 'name' => $this->actor->name, 'profile_picture' => $this->actor->profile_picture,
            ] : null,
        ];
    }
}
