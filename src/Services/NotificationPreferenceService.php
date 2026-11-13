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

    /**
     * Update supplied preferences, replacing each supplied category map independently.
     *
     * @param array<string, bool>|null $categories History and push eligibility by category.
     * @param array<string, bool>|null $pushCategories Push-only eligibility by category.
     */

    public function update(Model $owner, ?bool $enabled = null, ?bool $pushEnabled = null, ?array $categories = null, ?array $pushCategories = null): NotificationPreference
    {
        foreach (['Category' => $categories, 'Push category' => $pushCategories] as $label => $values) {
            foreach ($values ?? [] as $category => $value) {
                if (!is_string($category) || $category === '' || strlen($category) > 120 || !is_bool($value)) {
                    throw new InvalidArgumentException($label.' preferences must map category names to booleans.');
                }
            }
        }

        return $this->recipients->coordinate($owner, function (NotificationPreference $preference) use ($enabled, $pushEnabled, $categories, $pushCategories): NotificationPreference {
            $preference->fill(array_filter(['enabled' => $enabled, 'push_enabled' => $pushEnabled,
                'categories' => $categories, 'push_categories' => $pushCategories], fn (mixed $value): bool => $value !== null))->save();

            return $preference;
        });
    }
}
