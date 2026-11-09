<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Moztopia\Notifications\Models\NotificationPreference;

final class NotificationPreferenceService
{
    public function __construct(private readonly NotificationRecipientService $recipients)
    {
    }

    public function get(Model $owner): NotificationPreference
    {
        return NotificationPreference::firstOrCreate($this->recipients->identity($owner));
    }

    public function update(Model $owner, ?bool $enabled = null, ?bool $pushEnabled = null, ?array $categories = null): NotificationPreference
    {
        if ($categories !== null) {
            foreach ($categories as $category => $value) {
                if (!is_string($category) || $category === '' || strlen($category) > 120 || !is_bool($value)) {
                    throw new InvalidArgumentException('Category preferences must map category names to booleans.');
                }
            }
        }

        return $this->recipients->coordinate($owner, function (NotificationPreference $preference) use ($enabled, $pushEnabled, $categories): NotificationPreference {
            $preference->fill(array_filter(['enabled' => $enabled, 'push_enabled' => $pushEnabled,
                'categories' => $categories], fn (mixed $value): bool => $value !== null))->save();

            return $preference;
        });
    }
}
