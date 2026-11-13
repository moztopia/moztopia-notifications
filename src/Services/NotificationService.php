<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Moztopia\Notifications\Models\NotificationDelivery;
use Moztopia\Notifications\Models\NotificationDevice;
use Moztopia\Notifications\Models\NotificationMessage;
use Moztopia\Notifications\Models\NotificationPreference;
use Moztopia\Notifications\Models\NotificationHistory;
use Moztopia\Notifications\NotificationPayload;

final class NotificationService
{
    public function __construct(private readonly NotificationRecipientService $recipients, private readonly NotificationDeviceService $devices)
    {
    }

    public function send(Model $recipient, NotificationPayload $payload, ?string $idempotencyKey = null, ?string $deviceId = null, bool $immediate = false): ?NotificationMessage
    {
        return $this->recipients->coordinate($recipient, function (NotificationPreference $preference) use ($recipient, $payload, $idempotencyKey, $deviceId, $immediate): ?NotificationMessage {
            $identity = $this->recipients->identity($recipient);
            if ($deviceId !== null) {
                $this->devices->assertOwner(NotificationDevice::findOrFail($deviceId), $recipient);
            }
            if (!$preference->allows($payload->category)) {
                return null;
            }
            $hash = $idempotencyKey === null ? null : hash('sha256', $idempotencyKey);
            if ($hash !== null) {
                $existing = NotificationMessage::where($identity)->where('idempotency_hash', $hash)->first();
                if ($existing !== null && $existing->deduplicate_until->isFuture()) {
                    return $existing;
                }
                $existing?->update(['idempotency_hash' => null]);
            }
            $ttl = $payload->ttl ?? max(1, min(2419200, (int) config('moztopia-notifications.ttl')));
            $message = NotificationMessage::create([...$identity, 'idempotency_hash' => $hash, 'category' => $payload->category,
                'payload' => $payload->toArray(), 'expires_at' => now()->addSeconds($ttl),
                'deduplicate_until' => now()->addDays(max(1, (int) config('moztopia-notifications.deduplication_retention_days')))]);
            if (config('moztopia-notifications.history')) {
                NotificationHistory::create(['id' => $message->id, 'application' => $identity['application'],
                    'type' => $payload->category, 'notifiable_type' => $identity['owner_type'], 'notifiable_id' => $identity['owner_id'],
                    'data' => $payload->toArray()]);
            }
            if ($preference->allowsPush($payload->category)) {
                $devices = NotificationDevice::where($identity)->where('environment', config('moztopia-notifications.environment'))
                    ->whereNull('revoked_at')->where('push_enabled', true)->when($deviceId !== null, fn ($query) => $query->whereKey($deviceId))->get();
                foreach ($devices as $device) {
                    $delivery = NotificationDelivery::create(['message_id' => $message->id, 'device_id' => $device->id,
                        'generation' => $device->generation, 'transport' => $device->transport, 'transport_scope' => $device->transport_scope,
                        'environment' => $device->environment, 'available_at' => now()]);
                    DB::afterCommit(fn () => app(NotificationDeliveryService::class)->enqueue($delivery->id, $immediate));
                }
            }

            return $message;
        });
    }

    public function sendMany(iterable $recipients, NotificationPayload $payload, ?string $idempotencyKey = null, bool $immediate = false): array
    {
        $messages = [];
        foreach ($recipients as $recipient) {
            $messages[] = $this->send($recipient, $payload, $idempotencyKey, immediate: $immediate);
        }

        return $messages;
    }
}
