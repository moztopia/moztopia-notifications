<?php

declare(strict_types=1);

use Moztopia\Notifications\NotificationPayload;

it('uses configurable neutral defaults and rejects nonportable payloads', function (): void {
    config(['moztopia-notifications.defaults.category' => 'announcements', 'moztopia-notifications.defaults.priority' => 'high']);
    $payload = new NotificationPayload('Title', 'Body');
    expect($payload->category)->toBe('announcements')->and($payload->priority)->toBe('high');
    expect(fn () => new NotificationPayload('Title', 'Body', ttl: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new NotificationPayload('Title', 'Body', collapseId: str_repeat('x', 65)))->toThrow(InvalidArgumentException::class);
    expect(fn () => new NotificationPayload('Title', str_repeat('x', 3000)))->toThrow(InvalidArgumentException::class);
});
