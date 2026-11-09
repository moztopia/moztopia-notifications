<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Tests;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

final class NotificationTestRecipient extends Model
{
    use HasUuids;
    use Notifiable;

    protected $table = 'test_recipients';

    protected $guarded = [];
}
