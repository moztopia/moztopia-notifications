<?php

declare(strict_types=1);

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Event;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\QuotaExceeded;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Moztopia\Notifications\Notifications\NotificationPushMessage;
use Moztopia\Notifications\NotificationPayload;
use Moztopia\Notifications\Services\NotificationDeviceService;
use Moztopia\Notifications\Services\NotificationTransportService;
use Moztopia\Notifications\Tests\NotificationTestRecipient;
use Pushok\Client;
use Pushok\Response;

it('uses the real FCM channel with exactly one token and parses its returned acceptance report', function (): void {
    config(['moztopia-notifications.android_channels' => ['general' => 'activity_updates']]);
    $device = app(NotificationDeviceService::class)->register(NotificationTestRecipient::create(), 'one', 'android', 'secret-token');
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->withArgs(function ($message, $tokens): bool {
        expect($tokens)->toBe(['secret-token'])->and($message->toArray()['data']['notification_id'])->toBeString();
        expect($message->toArray()['android']['notification']['channel_id'])->toBe('activity_updates');

        return true;
    })->andReturn(MulticastSendReport::withItems([SendReport::success(MessageTarget::with('token', 'secret-token'), ['name' => 'safe-id'])]));
    $transport = Mockery::mock(NotificationTransportService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $transport->shouldReceive('fcmClient')->once()->andReturn($messaging);
    $notification = new NotificationPushMessage((new NotificationPayload('Title', 'Body'))->toArray(), (string) Illuminate\Support\Str::uuid7(), 60);
    expect($transport->send($device, $notification)['state'])->toBe('provider_accepted');
});

it('maps each configured category to its Android channel while preserving delivery metadata', function (string $category, string $channel): void {
    config(['moztopia-notifications.android_channels' => ['messages' => 'chat_messages', 'matches' => 'mutual_matches']]);
    $payload = new NotificationPayload('Title', 'Body', $category, deepLink: 'example://activity', data: ['event' => 'test'], priority: 'high', collapseId: 'thread-one');
    $notification = new NotificationPushMessage($payload->toArray(), 'event-id', 42);
    $fcm = $notification->toFcm(null)->toArray();
    expect($fcm['android'])->toBe(['priority' => 'high', 'ttl' => '42s', 'collapse_key' => 'thread-one', 'notification' => ['channel_id' => $channel]])
        ->and($fcm['notification']['title'])->toBe('Title')->and($fcm['notification']['body'])->toBe('Body')
        ->and($fcm['data']['notification_id'])->toBe('event-id')->and($fcm['data']['category'])->toBe($category)
        ->and($fcm['data']['deep_link'])->toBe('example://activity')->and(json_decode($fcm['data']['data'], true))->toBe(['event' => 'test']);
})->with([['messages', 'chat_messages'], ['matches', 'mutual_matches']]);

it('leaves Android channel selection absent for legacy or unmapped categories', function (): void {
    config(['moztopia-notifications.android_channels' => ['messages' => 'chat_messages']]);
    $notification = new NotificationPushMessage((new NotificationPayload('Title', 'Body', 'other'))->toArray(), 'event-id', 60);
    expect($notification->toFcm(null)->toArray()['android'])->not->toHaveKey('notification');
});

it('rejects invalid Android channel IDs instead of silently choosing another channel', function (mixed $channel): void {
    config(['moztopia-notifications.android_channels' => ['general' => $channel]]);
    $notification = new NotificationPushMessage((new NotificationPayload('Title', 'Body'))->toArray(), 'event-id', 60);
    expect(fn () => $notification->toFcm(null))->toThrow(InvalidArgumentException::class, 'channel IDs');
})->with(['', '   ', 42, false, [['unexpected' => 'array']]]);

it('uses the real APNs channel with exactly one token and parses acceptance', function (): void {
    $device = app(NotificationDeviceService::class)->register(NotificationTestRecipient::create(), 'one', 'ios', 'apns-token');
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('addNotification')->once();
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $client->shouldReceive('push')->once()->andReturn([$response]);
    $transport = Mockery::mock(NotificationTransportService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $transport->shouldReceive('apnsClient')->once()->andReturn($client);
    $notification = new NotificationPushMessage((new NotificationPayload('Title', 'Body'))->toArray(), (string) Illuminate\Support\Str::uuid7(), 60);
    expect($transport->send($device, $notification)['state'])->toBe('provider_accepted');
});

it('contains raw channel failure events and classifies definitive FCM invalidation', function (): void {
    Event::fake([NotificationFailed::class]);
    $device = app(NotificationDeviceService::class)->register(NotificationTestRecipient::create(), 'one', 'android', 'private-token');
    $error = (new NotFound('sensitive provider message'))->withErrors(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]]);
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->andReturn(MulticastSendReport::withItems([
        SendReport::failure(MessageTarget::with('token', 'private-token'), $error),
    ]));
    $transport = Mockery::mock(NotificationTransportService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $transport->shouldReceive('fcmClient')->once()->andReturn($messaging);
    $notification = new NotificationPushMessage((new NotificationPayload('Title', 'Body'))->toArray(), (string) Illuminate\Support\Str::uuid7(), 60);
    $result = $transport->send($device, $notification);
    expect($result['invalid_token'])->toBeTrue()->and($result['state'])->toBe('permanent_failure')
        ->and(json_encode($result))->not->toContain('private-token', 'sensitive provider message');
    Event::assertNotDispatched(NotificationFailed::class);
});

it('classifies transient and permanent FCM failures without invalidating malformed payload tokens', function (): void {
    $transport = app(NotificationTransportService::class);
    expect($transport->classify(new QuotaExceeded('sensitive'))['state'])->toBe('retryable_failure')
        ->and($transport->classify(new InvalidMessage('sensitive'))['state'])->toBe('permanent_failure')
        ->and($transport->classify(new InvalidMessage('sensitive')))->not->toHaveKey('invalid_token');
});

it('blocks a native recipient notification call that would bypass device tracking', function (): void {
    $notification = new NotificationPushMessage((new NotificationPayload('Title', 'Body'))->toArray(), (string) Illuminate\Support\Str::uuid7(), 60);
    expect(fn () => NotificationTestRecipient::create()->notify($notification))->toThrow(LogicException::class, 'per-device');
});

it('classifies actual APNs channel failure responses without leaking raw events', function (int $status, string $reason, string $state, bool $invalid): void {
    Event::fake([NotificationFailed::class]);
    $device = app(NotificationDeviceService::class)->register(NotificationTestRecipient::create(), 'one', 'ios', 'secret-token');
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('getStatusCode')->andReturn($status);
    $response->shouldReceive('getErrorReason')->andReturn($reason);
    $response->shouldReceive('getApnsId')->andReturn('safe-id');
    $response->shouldReceive('getDeviceToken')->andReturn('secret-token');
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('addNotification')->once();
    $client->shouldReceive('push')->once()->andReturn([$response]);
    $transport = Mockery::mock(NotificationTransportService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $transport->shouldReceive('apnsClient')->once()->andReturn($client);
    $notification = new NotificationPushMessage((new NotificationPayload('Title', 'Body'))->toArray(), (string) Illuminate\Support\Str::uuid7(), 60);
    $result = $transport->send($device, $notification);
    expect($result['state'])->toBe($state)->and($result['invalid_token'] ?? false)->toBe($invalid)
        ->and(json_encode($result))->not->toContain('secret-token');
    Event::assertNotDispatched(NotificationFailed::class);
})->with([[410, 'Unregistered', 'permanent_failure', true], [400, 'BadDeviceToken', 'permanent_failure', false],
    [429, 'TooManyRequests', 'retryable_failure', false], [503, 'ServiceUnavailable', 'retryable_failure', false]]);

it('retries the plain connection exception emitted by Pushok without exposing it', function (): void {
    $device = app(NotificationDeviceService::class)->register(NotificationTestRecipient::create(), 'one', 'ios', 'secret-token');
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('addNotification')->once();
    $client->shouldReceive('push')->once()->andThrow(new Exception('private connection diagnostic'));
    $transport = Mockery::mock(NotificationTransportService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $transport->shouldReceive('apnsClient')->once()->andReturn($client);
    $notification = new NotificationPushMessage((new NotificationPayload('Title', 'Body'))->toArray(), (string) Illuminate\Support\Str::uuid7(), 60);
    expect($transport->send($device, $notification))->toBe(['state' => 'retryable_failure', 'error_code' => 'apns_connection_failed']);
});
