<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Moztopia\Notifications\Jobs\NotificationDeliveryJob;
use Moztopia\Notifications\Models\NotificationDelivery;
use Moztopia\Notifications\Models\NotificationHistory;
use Moztopia\Notifications\NotificationPayload;
use Moztopia\Notifications\Services\NotificationDeliveryService;
use Moztopia\Notifications\Services\NotificationDeviceService;
use Moztopia\Notifications\Services\NotificationHistoryService;
use Moztopia\Notifications\Services\NotificationPreferenceService;
use Moztopia\Notifications\Services\NotificationService;
use Moztopia\Notifications\Services\NotificationTransportService;
use Moztopia\Notifications\Tests\NotificationTestRecipient;

beforeEach(function (): void {
    Queue::fake();
    $this->owner = NotificationTestRecipient::create();
    $this->devices = app(NotificationDeviceService::class);
    $this->sender = app(NotificationService::class);
    $this->payload = new NotificationPayload('Hello', 'World', 'updates');
    $this->transport = Mockery::mock(NotificationTransportService::class);
    app()->instance(NotificationTransportService::class, $this->transport);
    $this->deliveries = app(NotificationDeliveryService::class);
});

it('queues identity-only jobs after committing creation', function (): void {
    $this->devices->register($this->owner, 'one', 'android', 'private-token');
    DB::beginTransaction();
    $this->sender->send($this->owner, $this->payload);
    Queue::assertNothingPushed();
    DB::commit();
    Queue::assertPushed(NotificationDeliveryJob::class, function (NotificationDeliveryJob $job): bool {
        expect(serialize($job))->not->toContain('private-token', 'Hello', 'World');

        return $job->queue === 'notifications';
    });
});

it('does not enqueue or persist a rolled back submission', function (): void {
    $this->devices->register($this->owner, 'one', 'android', 'token');
    DB::beginTransaction();
    $this->sender->send($this->owner, $this->payload);
    DB::rollBack();
    Queue::assertNothingPushed();
    expect(NotificationHistory::count())->toBe(0)->and(NotificationDelivery::count())->toBe(0);
});

it('retries only the failed device and never duplicates history or accepted submissions', function (): void {
    foreach (['a', 'b', 'c'] as $name) {
        $this->devices->register($this->owner, $name, $name === 'c' ? 'ios' : 'android', $name);
    }
    $this->sender->send($this->owner, $this->payload, 'event');
    $this->transport->shouldReceive('send')->withArgs(fn ($device) => $device->installation_id !== 'b')
        ->twice()->andReturn(['state' => 'provider_accepted']);
    $this->transport->shouldReceive('send')->withArgs(fn ($device) => $device->installation_id === 'b')
        ->once()->andReturn(['state' => 'retryable_failure', 'error_code' => 'provider_unavailable']);
    foreach (NotificationDelivery::all() as $delivery) {
        $this->deliveries->attempt($delivery->id);
    }
    expect(NotificationDelivery::where('state', 'provider_accepted')->count())->toBe(2)
        ->and(NotificationDelivery::where('state', 'retryable_failure')->count())->toBe(1);
    $this->travel(31)->seconds();
    $this->transport->shouldReceive('send')->once()->andReturn(['state' => 'provider_accepted']);
    foreach (NotificationDelivery::all() as $delivery) {
        $this->deliveries->attempt($delivery->id);
    }
    expect(NotificationDelivery::where('state', 'provider_accepted')->count())->toBe(3)
        ->and(NotificationHistory::count())->toBe(1);
});

it('supports an explicit immediate single-device send', function (): void {
    $one = $this->devices->register($this->owner, 'one', 'android', 'one');
    $this->devices->register($this->owner, 'two', 'ios', 'two');
    $this->transport->shouldReceive('send')->once()->withArgs(fn ($device) => $device->id === $one->id)->andReturn(['state' => 'provider_accepted']);
    $this->sender->send($this->owner, $this->payload, deviceId: $one->id, immediate: true);
    Queue::assertNothingPushed();
    expect(NotificationDelivery::count())->toBe(1)->and(NotificationDelivery::first()->state)->toBe('provider_accepted');
});

it('skips work made ineligible after enqueueing', function (string $change): void {
    $device = $this->devices->register($this->owner, 'one', 'android', 'old');
    $message = $this->sender->send($this->owner, $this->payload);
    match ($change) {
        'rotate' => $this->devices->register($this->owner, 'one', 'android', 'new'),
        'remove' => $this->devices->remove($this->owner, $device->id),
        'reassign' => $this->devices->reassign($this->owner, $device->id, NotificationTestRecipient::create()),
        'device' => $this->devices->setPushEnabled($this->owner, $device->id, false),
        'global' => app(NotificationPreferenceService::class)->update($this->owner, enabled: false),
        'category' => app(NotificationPreferenceService::class)->update($this->owner, categories: ['updates' => false]),
        'push' => app(NotificationPreferenceService::class)->update($this->owner, pushEnabled: false),
        'delete' => app(NotificationHistoryService::class)->delete($this->owner, $message->id),
    };
    $this->transport->shouldNotReceive('send');
    $this->deliveries->attempt(NotificationDelivery::first()->id);
    expect(NotificationDelivery::first()->state)->toBe('skipped')
        ->and(NotificationHistory::count())->toBe($change === 'delete' ? 0 : 1);
})->with(['rotate', 'remove', 'reassign', 'device', 'global', 'category', 'push', 'delete']);

it('permanently rejects definitive invalid tokens and safely revokes only their generation', function (): void {
    $device = $this->devices->register($this->owner, 'one', 'android', 'old');
    $this->sender->send($this->owner, $this->payload);
    $this->transport->shouldReceive('send')->once()->andReturn(['state' => 'permanent_failure', 'error_code' => 'unregistered_token', 'invalid_token' => true]);
    $this->deliveries->attempt(NotificationDelivery::first()->id);
    expect($device->refresh()->token)->toBeNull()->and($device->generation)->toBe(2)
        ->and(NotificationDelivery::first()->state)->toBe('permanent_failure')->and(NotificationHistory::count())->toBe(1);
});

it('does not let a stale invalidation result remove a replacement token', function (): void {
    $device = $this->devices->register($this->owner, 'one', 'android', 'old');
    $this->sender->send($this->owner, $this->payload);
    $this->transport->shouldReceive('send')->once()->andReturnUsing(function () use ($device): array {
        $device->forceFill(['generation' => 2, 'token' => 'replacement'])->save();

        return ['state' => 'permanent_failure', 'invalid_token' => true, 'error_code' => 'unregistered_token'];
    });
    $this->deliveries->attempt(NotificationDelivery::first()->id);
    expect($device->refresh()->token)->toBe('replacement')->and($device->revoked_at)->toBeNull();
});

it('honors retry-after and exponential backoff without an early second attempt', function (): void {
    $this->devices->register($this->owner, 'one', 'android', 'token');
    $this->sender->send($this->owner, $this->payload);
    $this->transport->shouldReceive('send')->once()->andReturn(['state' => 'retryable_failure', 'error_code' => 'throttled', 'retry_after' => 300]);
    $id = NotificationDelivery::first()->id;
    expect($this->deliveries->attempt($id))->toBe(300)->and($this->deliveries->attempt($id))->toBe(300)
        ->and(NotificationDelivery::first()->attempts)->toBe(1);
});

it('expires stale work and stops at the maximum provider attempts', function (): void {
    $this->devices->register($this->owner, 'one', 'android', 'token');
    $this->sender->send($this->owner, new NotificationPayload('Short', 'Lived', ttl: 1));
    $this->travel(2)->seconds();
    $this->transport->shouldNotReceive('send');
    $this->deliveries->attempt(NotificationDelivery::first()->id);
    expect(NotificationDelivery::first()->state)->toBe('expired');
    $this->sender->send($this->owner, $this->payload);
    $delivery = NotificationDelivery::where('state', 'pending')->first();
    $delivery->update(['attempts' => 5]);
    $this->deliveries->attempt($delivery->id);
    expect($delivery->refresh()->error_code)->toBe('attempts_exhausted');
});

it('recovers delivery intents following queue dispatch failure', function (): void {
    $this->devices->register($this->owner, 'one', 'android', 'token');
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(Dispatcher::class, $bus);
    $this->sender->send($this->owner, $this->payload);
    expect(NotificationHistory::count())->toBe(1)->and(NotificationDelivery::first()->state)->toBe('pending');
    $bus->shouldReceive('dispatch')->once()->with(Mockery::type(NotificationDeliveryJob::class));
    expect($this->deliveries->recover())->toBe(1);
});

it('keeps tombstones after delivery cleanup without deleting user history', function (): void {
    config(['moztopia-notifications.delivery_retention_days' => 1]);
    $this->devices->register($this->owner, 'one', 'android', 'token');
    $message = $this->sender->send($this->owner, $this->payload, 'event');
    $this->travel(3)->days();
    expect($this->deliveries->cleanup())->toBe(1)->and(NotificationDelivery::count())->toBe(0)
        ->and(NotificationHistory::count())->toBe(1);
    $repeat = $this->sender->send($this->owner, $this->payload, 'event');
    expect($repeat->id)->toBe($message->id)->and(NotificationDelivery::count())->toBe(0);
});

it('never treats provider acceptance as confirmed receipt', function (): void {
    $this->devices->register($this->owner, 'one', 'android', 'token');
    $this->transport->shouldReceive('send')->once()->andReturn(['state' => 'provider_accepted']);
    $this->sender->send($this->owner, $this->payload, immediate: true);
    $delivery = NotificationDelivery::first();
    expect($delivery->state)->toBe('provider_accepted')->and($delivery->accepted_at)->not->toBeNull()
        ->and($delivery->getAttributes())->not->toHaveKeys(['received_at', 'displayed_at', 'opened_at']);
});
