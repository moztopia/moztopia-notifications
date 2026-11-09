<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Console;

use Illuminate\Console\Command;
use Moztopia\Notifications\Services\NotificationDeliveryService;

final class NotificationRecoverCommand extends Command
{
    protected $signature = 'moztopia-notifications:recover';

    protected $description = 'Enqueue due notification deliveries, including work stranded by a queue outage.';

    public function handle(NotificationDeliveryService $deliveries): int
    {
        $this->info('Queued '.$deliveries->recover().' eligible delivery intents.');

        return self::SUCCESS;
    }
}
