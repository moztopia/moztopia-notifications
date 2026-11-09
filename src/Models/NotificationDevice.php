<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class NotificationDevice extends Model
{
    use HasUuids;

    protected $table = 'moztopia_notification_devices';

    protected $guarded = ['*'];

    protected $hidden = ['token', 'token_fingerprint'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'generation' => 'integer', 'push_enabled' => 'boolean',
            'revoked_at' => 'immutable_datetime', 'last_active_at' => 'immutable_datetime'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
