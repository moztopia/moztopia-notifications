<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class NotificationDelivery extends Model
{
    use HasUuids;

    protected $table = 'moztopia_notification_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'attempts' => 'integer', 'available_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime'];
    }
}
