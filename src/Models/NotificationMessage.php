<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class NotificationMessage extends Model
{
    use HasUuids;

    protected $table = 'moztopia_notification_messages';

    protected $guarded = [];

    protected $hidden = ['payload', 'idempotency_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'immutable_datetime',
            'deduplicate_until' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }
}
