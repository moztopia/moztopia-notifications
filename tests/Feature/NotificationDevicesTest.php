<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Moztopia\Notifications\Models\NotificationDevice;
use Moztopia\Notifications\Services\NotificationDeviceService;
use Moztopia\Notifications\Tests\NotificationTestRecipient;

beforeEach(function (): void {
    $this->owner = NotificationTestRecipient::create();
    $this->other = NotificationTestRecipient::create();
    $this->devices = app(NotificationDeviceService::class);
});

it('registers multiple UUIDv7 devices and encrypts and hides provider tokens', function (): void {
    $android = $this->devices->register($this->owner, 'android-installation', 'android', 'secret-android');
    $ios = $this->devices->register($this->owner, 'ios-installation', 'ios', 'secret-ios');
    expect($android->id[14])->toBe('7')
        ->and($android->transport)->toBe('fcm')->and($ios->transport)->toBe('apns')
        ->and($android->owner->is($this->owner))->toBeTrue()
        ->and(NotificationDevice::count())->toBe(2)
        ->and($android->token)->toBe('secret-android')
        ->and(DB::table($android->getTable())->value('token'))->not->toContain('secret-android')
        ->and($android->toArray())->not->toHaveKeys(['token', 'token_fingerprint']);
});

it('updates an installation idempotently and versions token rotation', function (): void {
    $device = $this->devices->register($this->owner, 'installation', 'android', 'old');
    $repeat = $this->devices->register($this->owner, 'installation', 'android', 'old');
    $rotated = $this->devices->register($this->owner, 'installation', 'android', 'new');
    expect($repeat->id)->toBe($device->id)->and($repeat->generation)->toBe(1)
        ->and($rotated->generation)->toBe(2)->and(NotificationDevice::count())->toBe(1);
});

it('atomically prevents duplicate active tokens without revealing the token', function (): void {
    $this->devices->register($this->owner, 'one', 'android', 'secret');
    expect(fn () => $this->devices->register($this->other, 'two', 'android', 'secret'))
        ->toThrow(InvalidArgumentException::class, 'conflicts');
    expect(NotificationDevice::count())->toBe(1);
});

it('rejects cross-user rotation and removal and requires explicit reassignment', function (): void {
    $device = $this->devices->register($this->owner, 'installation', 'ios', 'secret');
    expect(fn () => $this->devices->register($this->other, 'installation', 'ios', 'stolen'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->devices->remove($this->other, $device->id))->toThrow(AuthorizationException::class);
    $reassigned = $this->devices->reassign($this->owner, $device->id, $this->other);
    expect($reassigned->owner_id)->toBe($this->other->id)->and($reassigned->generation)->toBe(2);
    $this->devices->remove($this->other, $device->id);
    expect($device->refresh()->token)->toBeNull()->and($device->revoked_at)->not->toBeNull();
});

it('isolates application and environment token fingerprints', function (): void {
    $first = $this->devices->register($this->owner, 'installation', 'android', 'shared');
    config(['moztopia-notifications.application' => 'another-application']);
    $second = $this->devices->register($this->owner, 'installation', 'android', 'shared');
    expect($first->token_fingerprint)->not->toBe($second->token_fingerprint);
    expect(fn () => $this->devices->remove($this->owner, $first->id))->toThrow(AuthorizationException::class);
    config(['moztopia-notifications.environment' => 'sandbox']);
    $third = $this->devices->register($this->owner, 'installation', 'android', 'shared');
    expect($third->token_fingerprint)->not->toBe($second->token_fingerprint);
});

it('requires an explicit fingerprint key and a valid platform', function (): void {
    expect(fn () => $this->devices->register($this->owner, 'i', 'web', 'token'))->toThrow(InvalidArgumentException::class);
    config(['moztopia-notifications.fingerprint_key' => null]);
    expect(fn () => $this->devices->register($this->owner, 'i', 'android', 'token'))->toThrow(InvalidArgumentException::class);
});
