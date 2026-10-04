<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Sent synchronously (it already runs inside EnviarPushReservaOnline).
 * The payload is flat ({ title, body, url, tag, timestamp }) because the
 * service worker reads it directly with event.data.json().
 */
class NuevaReservaOnline extends Notification
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
                // Deliver fast, but do not pile up stale "new booking" pushes for a device that stays offline.
                return ['TTL' => 3600, 'urgency' => 'high'];
            }
        };
    }
}
