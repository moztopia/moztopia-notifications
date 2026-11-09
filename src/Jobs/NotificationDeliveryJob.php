<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Moztopia\Notifications\Services\NotificationDeliveryService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotificationDeliveryJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly string $deliveryId)
    {
        $this->tries = (int) config('moztopia-notifications.queue.tries');
        $this->timeout = (int) config('moztopia-notifications.queue.timeout');
        $this->onConnection(config('moztopia-notifications.queue.connection'));
        $this->onQueue(config('moztopia-notifications.queue.name'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return config('moztopia-notifications.queue.backoff');
    }

    public function handle(NotificationDeliveryService $deliveries): void
    {
        $delay = $deliveries->attempt($this->deliveryId);
        if ($delay !== null && $this->job !== null) {
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning(
            'Notification queue job failed; delivery intent remains recoverable if eligible.',
            ['delivery_id' => $this->deliveryId, 'error_code' => 'queue_job_failed']
        );
    }
}
