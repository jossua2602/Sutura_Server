<?php

namespace App\Notifications;

use App\Models\Appointment;
use Carbon\Carbon;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "customer forgot their fitting" pain point named directly in the
 * tailoring-store interview research — sent ~24h ahead of a still-pending/
 * confirmed appointment by App\Console\Commands\RemindUpcomingAppointments.
 * Mirrors AppointmentStatusNotification's channel/copy structure, but this
 * one isn't triggered by a status change.
 */
class AppointmentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Appointment $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->hasMailableEmail()) {
            $channels[] = 'mail';
        }
        $channels[] = SmsChannel::class;

        return $channels;
    }

    public function toSms(object $notifiable): array
    {
        return SmsTemplates::reminder($this->appointment) + ['store_id' => $this->appointment->store_id, 'related_type' => 'appointment', 'related_id' => $this->appointment->id];
    }

    private function scheduledLabel(): string
    {
        return $this->appointment->scheduled_at
            ? Carbon::parse($this->appointment->scheduled_at)->format('M d, Y h:i A')
            : 'N/A';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $store = $this->appointment->store;
        $storeUrl = $store?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000').'/store/'.$store->slug) : null;
        $type = ucfirst($this->appointment->appointment_type ?? 'appointment');

        $mail = (new MailMessage)
            ->subject('Reminder: Your '.$type.' Tomorrow — '.($store?->name ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line('This is a friendly reminder that you have a '.strtolower($type).' appointment tomorrow, '.$this->scheduledLabel().'.');

        if ($store?->address) {
            $mail->line('Location: '.$store->address);
        }

        if ($storeUrl) {
            $mail->action('View Appointment', $storeUrl);
        }

        return $mail->line('See you soon!');
    }

    public function toArray(object $notifiable): array
    {
        $store = $this->appointment->store;

        return [
            'type' => 'appointment_reminder',
            'title' => 'Appointment Reminder',
            'message' => 'Reminder: your appointment is scheduled for '.$this->scheduledLabel().'.',
            'action_url' => '/account/appointments/'.$this->appointment->id,
            'appointment_id' => $this->appointment->id,
            'scheduled_at' => $this->appointment->scheduled_at,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
        ];
    }
}
