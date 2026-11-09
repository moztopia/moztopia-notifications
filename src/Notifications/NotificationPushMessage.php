<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Notifications;

use DateTime;
use Illuminate\Notifications\Notification;
use LogicException;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Apn\ApnMessagePriority;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmAlert;
use Pushok\Client;

final class NotificationPushMessage extends Notification
{
    private ?Client $apnsClient = null;

    public function withApnsClient(Client $client): self
    {
        $this->apnsClient = $client;

        return $this;
    }

    public function __construct(private readonly array $payload, string $identity, private readonly int $remainingTtl)
    {
        $this->id = $identity;
    }

    public function via(mixed $notifiable): array
    {
        throw new LogicException('Use the Moztopia Notifications service to preserve per-device delivery invariants.');
    }

    public function toFcm(mixed $notifiable): FcmMessage
    {
        return FcmMessage::create()
            ->notification(new FcmAlert(title: $this->payload['title'], body: $this->payload['body'], image: $this->payload['imageUrl']))
            ->data($this->custom())
            ->android(array_filter(['priority' => $this->payload['priority'], 'ttl' => $this->remainingTtl.'s',
                'collapse_key' => $this->payload['collapseId']]));
    }

    public function toApn(mixed $notifiable): ApnMessage
    {
        $message = ApnMessage::create($this->payload['title'], $this->payload['body'], $this->custom())
            ->category($this->payload['category'])->apnsId($this->id)
            ->priority($this->payload['priority'] === 'high' ? ApnMessagePriority::High : ApnMessagePriority::Low)
            ->expiresAt(new DateTime('+'.$this->remainingTtl.' seconds'))
            ->collapseId($this->payload['collapseId'])->threadId($this->payload['collapseId']);
        if ($this->payload['imageUrl'] !== null) {
            $message->mutableContent();
        }
        if ($this->apnsClient !== null) {
            $message->via($this->apnsClient);
        }

        return $message;
    }

    private function custom(): array
    {
        return array_filter(['notification_id' => $this->id, 'category' => $this->payload['category'],
            'deep_link' => $this->payload['deepLink'], 'image_url' => $this->payload['imageUrl'],
            'data' => json_encode($this->payload['data'], JSON_THROW_ON_ERROR)], fn (mixed $value): bool => $value !== null);
    }
}
