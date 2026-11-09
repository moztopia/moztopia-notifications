<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Moztopia\Notifications\Models\NotificationDelivery;
use Moztopia\Notifications\NotificationPayload;
use Moztopia\Notifications\Services\NotificationDeliveryService;
use Moztopia\Notifications\Services\NotificationDeviceService;
use Moztopia\Notifications\Services\NotificationService;
use Moztopia\Notifications\Services\NotificationTransportService;
use Moztopia\Notifications\Tests\NotificationTestRecipient;

it('coordinates token rotation, reassignment, deletion, preferences and duplicate claims across independent SQLite connections', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'notification-concurrency-');
    config(['database.connections.testing.database' => $path]);
    DB::purge('testing');
    try {
        (require __DIR__.'/../../database/migrations/2026_11_09_000001_create_moztopia_notification_tables.php')->up();
        Schema::create('test_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
        Queue::fake();
        $owner = NotificationTestRecipient::create();
        $device = app(NotificationDeviceService::class)->register($owner, 'installation', 'android', 'token');
        app(NotificationService::class)->send($owner, new NotificationPayload('Title', 'Body'));
        $delivery = NotificationDelivery::first();
        $other = new PDO('sqlite:'.$path);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec('PRAGMA busy_timeout = 0');
        $transport = Mockery::mock(NotificationTransportService::class);
        $transport->shouldReceive('send')->once()->andReturnUsing(function () use ($other, $delivery): array {
            expect(DB::transactionLevel())->toBeGreaterThan(0);
            $mutations = [
                'UPDATE moztopia_notification_devices SET generation = generation + 1',
                "UPDATE moztopia_notification_devices SET owner_id = 'different-owner'",
                'UPDATE moztopia_notification_messages SET cancelled_at = CURRENT_TIMESTAMP',
                'UPDATE moztopia_notification_preferences SET enabled = 0',
                'UPDATE moztopia_notification_preferences SET updated_at = CURRENT_TIMESTAMP',
            ];
            foreach ($mutations as $sql) {
                expect(fn () => $other->exec($sql))->toThrow(PDOException::class, 'locked');
            }
            expect(app(NotificationDeliveryService::class)->attempt($delivery->id))->toBeNull();

            return ['state' => 'provider_accepted'];
        });
        app()->instance(NotificationTransportService::class, $transport);
        app(NotificationDeliveryService::class)->attempt($delivery->id);
        expect($delivery->refresh()->state)->toBe('provider_accepted');
        $other->exec('UPDATE moztopia_notification_devices SET generation = generation + 1');
        expect($device->refresh()->generation)->toBe(2);
        expect(app(NotificationDeliveryService::class)->attempt($delivery->id))->toBeNull();
    } finally {
        DB::disconnect('testing');
        unlink($path);
    }
});
