<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Moztopia\Notifications\Jobs\NotificationDeliveryJob;
use Moztopia\Notifications\Models\NotificationDelivery;
use Moztopia\Notifications\Models\NotificationDevice;
use Moztopia\Notifications\Models\NotificationMessage;
use Moztopia\Notifications\Models\NotificationPreference;
use Moztopia\Notifications\Notifications\NotificationPushMessage;
use Throwable;

final class NotificationDeliveryService
{
    public function __construct(private readonly NotificationRecipientService $recipients, private readonly NotificationDeviceService $devices, private readonly NotificationTransportService $transport)
    {
    }

    public function enqueue(string $deliveryId, bool $immediate = false): void
    {
        try {
            if ($immediate) {
                $this->attempt($deliveryId);
            } else {
                app(Dispatcher::class)->dispatch(new NotificationDeliveryJob($deliveryId));
            }
        } catch (Throwable) {
            Log::warning('Notification queue dispatch requires recovery.', ['delivery_id' => $deliveryId, 'error_code' => 'enqueue_failed']);
        }
    }

    public function attempt(string $deliveryId): ?int
    {
        $delivery = NotificationDelivery::find($deliveryId);
        if ($delivery !== null && $delivery->environment !== config('moztopia-notifications.environment')) {
            return null;
        }
        $message = $delivery === null ? null : NotificationMessage::find($delivery->message_id);
        if ($message === null || $message->application !== $this->recipients->application()) {
            return null;
        }
        $ownerClass = Relation::getMorphedModel($message->owner_type) ?? $message->owner_type;
        if (!is_a($ownerClass, Model::class, true)) {
            $this->skipOrphan($deliveryId);

            return null;
        }
        $owner = $ownerClass::find($message->owner_id);
        if ($owner === null) {
            $this->skipOrphan($deliveryId);

            return null;
        }

        return $this->recipients->coordinate($owner, function (NotificationPreference $preference) use ($deliveryId, $owner): ?int {
            $messageId = NotificationDelivery::whereKey($deliveryId)->value('message_id');
            if ($messageId === null) {
                return null;
            }
            $message = NotificationMessage::whereKey($messageId)->lockForUpdate()->first();
            $delivery = NotificationDelivery::whereKey($deliveryId)->lockForUpdate()->first();
            if ($message === null || $delivery === null || !in_array($delivery->state, ['pending', 'retryable_failure'], true)) {
                return null;
            }
            $device = NotificationDevice::whereKey($delivery->device_id)->lockForUpdate()->first();
            if ($message->cancelled_at !== null || $message->payload === null || !$preference->allowsPush($message->category)
                || $device === null || $device->revoked_at !== null || !$device->push_enabled || $device->token === null
                || $device->owner_type !== $owner->getMorphClass() || $device->owner_id !== (string) $owner->getKey()
                || $device->application !== $message->application || $device->generation !== $delivery->generation
                || $device->transport !== $delivery->transport || $device->transport_scope !== $delivery->transport_scope
                || $device->transport_scope !== $this->devices->scope($device->transport)
                || $device->environment !== $delivery->environment || $device->environment !== config('moztopia-notifications.environment')) {
                $delivery->update(['state' => 'skipped', 'error_code' => 'ineligible']);

                return null;
            }
            if ($message->expires_at->isPast()) {
                $delivery->update(['state' => 'expired', 'error_code' => 'ttl_expired']);

                return null;
            }
            if ($delivery->attempts >= (int) config('moztopia-notifications.queue.tries')) {
                $delivery->update(['state' => 'permanent_failure', 'error_code' => 'attempts_exhausted']);

                return null;
            }
            if ($delivery->available_at->isFuture()) {
                return max(1, $delivery->available_at->getTimestamp() - now()->getTimestamp());
            }
            $delivery->update(['state' => 'processing', 'attempts' => $delivery->attempts + 1]);
            $result = $this->transport->send($device, new NotificationPushMessage(
                $message->payload,
                $message->id,
                max(1, $message->expires_at->getTimestamp() - now()->getTimestamp())
            ));
            $state = $result['state'];
            $errorCode = $result['error_code'] ?? null;
            $delay = null;
            if ($state === 'retryable_failure') {
                if ($delivery->attempts >= (int) config('moztopia-notifications.queue.tries')) {
                    $state = 'permanent_failure';
                    $errorCode = 'attempts_exhausted';
                } else {
                    $backoff = config('moztopia-notifications.queue.backoff');
                    $delay = max(1, (int) ($backoff[min($delivery->attempts - 1, count($backoff) - 1)] ?? 60), (int) ($result['retry_after'] ?? 0));
                }
            }
            $delivery->update(['state' => $state, 'error_code' => $errorCode,
                'accepted_at' => $state === 'provider_accepted' ? now() : null, 'available_at' => now()->addSeconds($delay ?? 0)]);
            if (($result['invalid_token'] ?? false) && config('moztopia-notifications.invalidate_tokens')) {
                NotificationDevice::whereKey($device->id)->where('generation', $delivery->generation)
                    ->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey())
                    ->update(['token' => null, 'token_fingerprint' => null, 'revoked_at' => now(), 'generation' => $delivery->generation + 1]);
            }
            Log::info('Notification device attempt completed.', ['delivery_id' => $deliveryId, 'state' => $state,
                'attempt' => $delivery->attempts, 'error_code' => $errorCode]);

            return $delay;
        });
    }

    public function recover(): int
    {
        $deliveries = NotificationDelivery::whereIn('state', ['pending', 'retryable_failure'])->where('available_at', '<=', now())
            ->where('environment', config('moztopia-notifications.environment'))
            ->whereIn('message_id', NotificationMessage::where('application', $this->recipients->application())->select('id'))
            ->orderBy('available_at')->limit((int) config('moztopia-notifications.recovery_batch'))->get();
        foreach ($deliveries as $delivery) {
            $this->enqueue($delivery->id);
        }

        return $deliveries->count();
    }

    public function cleanup(): int
    {
        $count = 0;
        $ids = NotificationMessage::where('application', $this->recipients->application())->where('expires_at', '<', now())
            ->where('updated_at', '<', now()->subDays(max(1, (int) config('moztopia-notifications.delivery_retention_days'))))
            ->orderBy('id')->limit((int) config('moztopia-notifications.recovery_batch'))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $message = NotificationMessage::whereKey($id)->lockForUpdate()->first();
                if ($message === null) {
                    return;
                }
                NotificationDelivery::where('message_id', $id)->delete();
                if ($message->deduplicate_until->isPast()) {
                    $message->delete();
                } else {
                    $message->update(['payload' => null]);
                }
                $count++;
            }, 1);
        }

        return $count;
    }

    private function skipOrphan(string $deliveryId): void
    {
        NotificationDelivery::whereKey($deliveryId)->whereIn('state', ['pending', 'retryable_failure'])
            ->update(['state' => 'skipped', 'error_code' => 'recipient_missing']);
    }
}
