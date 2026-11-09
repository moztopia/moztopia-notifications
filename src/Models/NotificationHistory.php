<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Models;

use Illuminate\Notifications\DatabaseNotification;

final class NotificationHistory extends DatabaseNotification
{
    protected $table = 'moztopia_notification_history';
}
