<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class NotificationPreference extends Model
{
    use HasUuids;

    protected $table = 'moztopia_notification_preferences';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'push_enabled' => 'boolean', 'categories' => 'array', 'push_categories' => 'array'];
    }

    public function allows(string $category): bool
    {
        return $this->enabled && (($this->categories ?? [])[$category] ?? true);
    }

    /**
     * Check history eligibility, user push preferences and application category policy.
     */

    public function allowsPush(string $category): bool
    {
        $policy = config('moztopia-notifications.push_categories', []);

        return $this->allows($category) && $this->push_enabled
            && (($this->push_categories ?? [])[$category] ?? true)
            && ($policy[$category] ?? true);
    }
}
