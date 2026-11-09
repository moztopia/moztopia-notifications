<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Moztopia\Notifications\Models\NotificationDelivery;
use Moztopia\Notifications\Models\NotificationHistory;
use Moztopia\Notifications\Models\NotificationMessage;
use Moztopia\Notifications\NotificationPayload;
use Moztopia\Notifications\Services\NotificationDeviceService;
use Moztopia\Notifications\Services\NotificationHistoryService;
use Moztopia\Notifications\Services\NotificationPreferenceService;
use Moztopia\Notifications\Services\NotificationService;
use Moztopia\Notifications\Tests\NotificationTestRecipient;

beforeEach(function (): void {
    Queue::fake();
    $this->owner = NotificationTestRecipient::create();
    $this->sender = app(NotificationService::class);
    $this->history = app(NotificationHistoryService::class);
    $this->preferences = app(NotificationPreferenceService::class);
    $this->payload = new NotificationPayload('Hello', 'World', 'updates');
});

it('persists one native history record per recipient independently of device count', function (): void {
    foreach (range(1, 5) as $number) {
        app(NotificationDeviceService::class)->register($this->owner, 'installation-'.$number, 'android', 'token-'.$number);
    }
    $message = $this->sender->send($this->owner, $this->payload, 'event');
    $repeat = $this->sender->send($this->owner, $this->payload, 'event');
    expect($repeat->id)->toBe($message->id)->and(NotificationHistory::count())->toBe(1)
        ->and(NotificationDelivery::count())->toBe(5)->and(NotificationMessage::count())->toBe(1);
});

it('scopes idempotency to application and recipient', function (): void {
    $other = NotificationTestRecipient::create();
    $this->sender->sendMany([$this->owner, $other], $this->payload, 'same-event');
    config(['moztopia-notifications.application' => 'second-app']);
    $this->sender->send($this->owner, $this->payload, 'same-event');
    expect(NotificationHistory::count())->toBe(3)->and($this->history->query($this->owner)->count())->toBe(1);
});

it('enforces the exact creation preference matrix', function (string $setting, bool $history): void {
    app(NotificationDeviceService::class)->register($this->owner, 'installation', 'android', 'token');
    match ($setting) {
        'global' => $this->preferences->update($this->owner, enabled: false),
        'category' => $this->preferences->update($this->owner, categories: ['updates' => false]),
        'push' => $this->preferences->update($this->owner, pushEnabled: false),
        'device' => app(NotificationDeviceService::class)->setPushEnabled($this->owner, \Moztopia\Notifications\Models\NotificationDevice::first()->id, false),
    };
    $this->sender->send($this->owner, $this->payload);
    expect(NotificationHistory::count())->toBe($history ? 1 : 0)->and(NotificationDelivery::count())->toBe(0);
})->with([['global', false], ['category', false], ['push', true], ['device', true]]);

it('preserves no-device history and supports native read status with owner-scoped operations', function (): void {
    $message = $this->sender->send($this->owner, $this->payload);
    expect($this->history->paginate($this->owner)->total())->toBe(1)->and($this->history->unreadCount($this->owner))->toBe(1);
    $this->history->markAsRead($this->owner, $message->id);
    expect($this->history->unreadCount($this->owner))->toBe(0);
    $this->history->markAsUnread($this->owner, $message->id);
    $this->history->markAllAsRead($this->owner);
    expect($this->history->unreadCount($this->owner))->toBe(0);
    $other = NotificationTestRecipient::create();
    expect(fn () => $this->history->delete($other, $message->id))->toThrow(ModelNotFoundException::class);
});

it('cancels pending deliveries on deletion and retains event deduplication', function (): void {
    app(NotificationDeviceService::class)->register($this->owner, 'installation', 'android', 'token');
    $message = $this->sender->send($this->owner, $this->payload, 'event');
    $this->history->delete($this->owner, $message->id);
    $repeat = $this->sender->send($this->owner, $this->payload, 'event');
    expect($repeat->id)->toBe($message->id)->and(NotificationHistory::count())->toBe(0)
        ->and(NotificationDelivery::first()->state)->toBe('skipped')
        ->and($message->refresh()->payload)->toBeNull();
});

it('supports history-disabled idempotent delivery metadata', function (): void {
    config(['moztopia-notifications.history' => false]);
    app(NotificationDeviceService::class)->register($this->owner, 'installation', 'android', 'token');
    $first = $this->sender->send($this->owner, $this->payload, 'event');
    $second = $this->sender->send($this->owner, $this->payload, 'event');
    expect($first->id)->toBe($second->id)->and(NotificationHistory::count())->toBe(0)->and(NotificationDelivery::count())->toBe(1);
});

it('accepts a new event after the configured deduplication window', function (): void {
    $first = $this->sender->send($this->owner, $this->payload, 'event');
    $this->travel(31)->days();
    $second = $this->sender->send($this->owner, $this->payload, 'event');
    expect($first->id)->not->toBe($second->id)->and(NotificationHistory::count())->toBe(2);
});
