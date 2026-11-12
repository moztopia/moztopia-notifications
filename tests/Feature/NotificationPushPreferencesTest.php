<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Moztopia\Notifications\Models\NotificationDelivery;
use Moztopia\Notifications\Models\NotificationHistory;
use Moztopia\Notifications\Models\NotificationPreference;
use Moztopia\Notifications\NotificationPayload;
use Moztopia\Notifications\Services\NotificationDeviceService;
use Moztopia\Notifications\Services\NotificationPreferenceService;
use Moztopia\Notifications\Services\NotificationService;
use Moztopia\Notifications\Tests\NotificationTestRecipient;

beforeEach(function (): void {
    Queue::fake();
    $this->owner = NotificationTestRecipient::create();
    $this->preferences = app(NotificationPreferenceService::class);
    $this->sender = app(NotificationService::class);
    app(NotificationDeviceService::class)->register($this->owner, 'android', 'android', 'android-token');
    app(NotificationDeviceService::class)->register($this->owner, 'ios', 'ios', 'ios-token');
});

it('mutes one category on every device while retaining its single history entry', function (): void {
    $this->preferences->update($this->owner, pushCategories: ['messages' => false]);
    $muted = $this->sender->send($this->owner, new NotificationPayload('Update', 'Open the app', 'messages'), 'message-event');
    $this->sender->send($this->owner, new NotificationPayload('Update', 'Open the app', 'matches'));
    expect(NotificationHistory::count())->toBe(2)->and(NotificationDelivery::count())->toBe(2)
        ->and(NotificationDelivery::where('message_id', $muted->id)->count())->toBe(0)
        ->and(NotificationHistory::findOrFail($muted->id)->read_at)->toBeNull();
    $this->preferences->update($this->owner, pushCategories: ['messages' => true]);
    $repeat = $this->sender->send($this->owner, new NotificationPayload('Update', 'Open the app', 'messages'), 'message-event');
    expect($repeat->id)->toBe($muted->id)->and(NotificationHistory::count())->toBe(2)
        ->and(NotificationDelivery::where('message_id', $muted->id)->count())->toBe(0);
    $this->sender->send($this->owner, new NotificationPayload('New', 'Open the app', 'messages'), 'new-event');
    expect(NotificationDelivery::count())->toBe(4);
});

it('keeps configured categories history-only even when the member explicitly enables push', function (): void {
    config(['moztopia-notifications.push_categories' => ['visits' => false]]);
    $this->preferences->update($this->owner, pushCategories: ['visits' => true]);
    $this->sender->send($this->owner, new NotificationPayload('Visit', 'Open the app', 'visits'));
    expect(NotificationHistory::count())->toBe(1)->and(NotificationDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('preserves global and category suppression when push alone is enabled', function (string $setting): void {
    if ($setting === 'global') {
        $this->preferences->update($this->owner, enabled: false, pushCategories: ['messages' => true]);
    } elseif ($setting === 'category') {
        $this->preferences->update($this->owner, categories: ['messages' => false], pushCategories: ['messages' => true]);
    } else {
        $this->preferences->update($this->owner, pushEnabled: false, pushCategories: ['messages' => true]);
    }
    $this->sender->send($this->owner, new NotificationPayload('Update', 'Open the app', 'messages'));
    expect(NotificationHistory::count())->toBe($setting === 'push' ? 1 : 0)->and(NotificationDelivery::count())->toBe(0);
})->with(['global', 'category', 'push']);

it('updates and replaces push maps without changing other preferences or another recipient', function (): void {
    $other = NotificationTestRecipient::create();
    $this->preferences->update($other, pushCategories: ['messages' => false]);
    $this->preferences->update($this->owner, categories: ['visits' => false], pushCategories: ['messages' => false]);
    $this->preferences->update($this->owner, pushEnabled: false);
    $preference = $this->preferences->get($this->owner);
    expect($preference->push_categories)->toBe(['messages' => false])->and($preference->categories)->toBe(['visits' => false]);
    $this->preferences->update($this->owner, pushEnabled: true, pushCategories: []);
    expect($preference->refresh()->allowsPush('messages'))->toBeTrue()
        ->and($preference->allowsPush('visits'))->toBeFalse()
        ->and($this->preferences->get($other)->allowsPush('messages'))->toBeFalse();
});

it('rejects malformed push maps before persisting any preference changes', function (array $map): void {
    $before = $this->preferences->get($this->owner);
    expect(fn () => $this->preferences->update($this->owner, enabled: false, pushCategories: $map))
        ->toThrow(InvalidArgumentException::class, 'Push category');
    expect($before->refresh()->enabled)->toBeTrue()->and($before->push_categories)->toBeNull();
})->with([[['messages' => 'false']], [['messages' => 0]], [['' => true]], [[str_repeat('a', 121) => true]], [[0 => false]]]);

it('upgrades and rolls back the additive column without losing legacy settings or history', function (): void {
    $preference = $this->preferences->update($this->owner, categories: ['messages' => false]);
    $message = $this->sender->send($this->owner, new NotificationPayload('Update', 'Open the app', 'matches'));
    NotificationHistory::findOrFail($message->id)->markAsRead();
    $migration = require __DIR__.'/../../database/migrations/2026_11_12_000001_add_push_categories_to_moztopia_notification_preferences.php';
    $migration->down();
    expect(Schema::hasColumn('moztopia_notification_preferences', 'push_categories'))->toBeFalse()
        ->and(DB::table('moztopia_notification_preferences')->where('id', $preference->id)->count())->toBe(1);
    $migration->up();
    $legacy = NotificationPreference::findOrFail($preference->id);
    expect($legacy->push_categories)->toBeNull()->and($legacy->allowsPush('matches'))->toBeTrue()
        ->and($legacy->allowsPush('messages'))->toBeFalse()
        ->and(NotificationHistory::findOrFail($message->id)->read_at)->not->toBeNull()
        ->and(NotificationHistory::count())->toBe(1)->and(NotificationDelivery::count())->toBe(2);
});
