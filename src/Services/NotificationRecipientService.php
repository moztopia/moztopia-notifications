<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Moztopia\Notifications\Models\NotificationPreference;

final class NotificationRecipientService
{
    public function application(): string
    {
        $application = config('moztopia-notifications.application');
        if (!is_string($application) || $application === '' || strlen($application) > 120) {
            throw new InvalidArgumentException('Configure a notification application identifier (1–120 bytes).');
        }

        return $application;
    }

    public function identity(Model $owner): array
    {
        if (!$owner->exists || !Str::isUuid((string) $owner->getKey())) {
            throw new InvalidArgumentException('Notification recipients must be persisted UUID models.');
        }
        if ($owner->getConnection()->getName() !== DB::connection()->getName()) {
            throw new InvalidArgumentException('Notification recipients and package records must use the same database connection.');
        }

        return ['application' => $this->application(), 'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey()];
    }

    public function coordinate(Model $owner, Closure $operation): mixed
    {
        $identity = $this->identity($owner);
        NotificationPreference::firstOrCreate($identity);

        return DB::transaction(function () use ($identity, $operation): mixed {
            NotificationPreference::where($identity)->update(['updated_at' => now()]);
            $preference = NotificationPreference::where($identity)->lockForUpdate()->firstOrFail();

            return $operation($preference);
        }, 1);
    }
}
