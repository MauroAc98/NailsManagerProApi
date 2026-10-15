<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Sent synchronously (it already runs inside EnviarPushFalloWhatsapp).
 * The payload is flat ({ title, body, url, tag, timestamp }) because the
 * service worker reads it directly with event.data.json().
 */
class FalloMensajeWhatsapp extends Notification
{
    /** @param array{title: string, body: string, url: string, tag: string, timestamp: int} $payload */
    public function __construct(public array $payload)
    {
    }

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessageInterface
    {
        $payload = $this->payload;

        return new class($payload) implements WebPushMessageInterface {
            public function __construct(private array $payload)
            {
            }

            public function toArray(): array
            {
                return $this->payload;
            }

            public function getOptions(): array
            {
                // A failed message stays actionable (the owner can resend it by hand)
                // longer than a new booking does: keep it queued for up to 6 hours.
                return ['TTL' => 21600, 'urgency' => 'high'];
            }
        };
    }
}
