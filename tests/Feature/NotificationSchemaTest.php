<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('keeps every package index identifier within the MySQL and MariaDB limit', function (): void {
    foreach (['history', 'preferences', 'devices', 'messages', 'deliveries'] as $table) {
        foreach (Schema::getIndexes('moztopia_notification_'.$table) as $index) {
            expect(strlen($index['name']))->toBeLessThanOrEqual(64);
        }
    }
});
