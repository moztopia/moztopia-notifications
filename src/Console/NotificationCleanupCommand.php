<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Console;

use Illuminate\Console\Command;
use Moztopia\Notifications\Services\NotificationDeliveryService;

final class NotificationCleanupCommand extends Command
{
    protected $signature = 'moztopia-notifications:cleanup';

    protected $description = 'Remove expired delivery details while preserving deduplication metadata and visible history.';

    public function handle(NotificationDeliveryService $deliveries): int
    {
        $this->info('Cleaned '.$deliveries->cleanup().' expired notification messages.');

        return self::SUCCESS;
    }
}
