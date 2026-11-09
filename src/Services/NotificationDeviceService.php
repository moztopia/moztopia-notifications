<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Moztopia\Notifications\Models\NotificationDevice;
use SensitiveParameter;

final class NotificationDeviceService
{
    public function __construct(private readonly NotificationRecipientService $recipients)
    {
    }

    public function scope(string $transport): string
    {
        return hash('sha256', json_encode([$this->recipients->application(),
            config('moztopia-notifications.environment'), $transport,
            $transport === 'fcm' ? config('moztopia-notifications.fcm_project') : config('moztopia-notifications.apns.app_bundle_id'),
            $transport === 'fcm' ? config('moztopia-notifications.fcm_project_id') : null,
        ], JSON_THROW_ON_ERROR));
    }

    public function register(
        Model $owner,
        string $installationId,
        string $platform,
        #[SensitiveParameter] string $token,
        ?string $name = null,
    ): NotificationDevice {
        if (!in_array($platform, ['android', 'ios'], true) || $installationId === '' || strlen($installationId) > 191
            || $token === '' || strlen($token) > 4096 || ($name !== null && strlen($name) > 255)) {
            throw new InvalidArgumentException('Invalid device registration fields.');
        }
        $environment = config('moztopia-notifications.environment');
        if (!in_array($environment, ['development', 'sandbox', 'production'], true)) {
            throw new InvalidArgumentException('Configure a valid notification transport environment.');
        }
        $key = config('moztopia-notifications.fingerprint_key');
        if (!is_string($key) || strlen($key) < 32) {
            throw new InvalidArgumentException('Configure a dedicated notification fingerprint key of at least 32 bytes.');
        }
        $transport = $platform === 'android' ? 'fcm' : 'apns';
        $scope = $this->scope($transport);
        $fingerprint = hash_hmac('sha256', json_encode([$scope, $token], JSON_THROW_ON_ERROR), $key);

        try {
            return $this->recipients->coordinate($owner, function () use ($owner, $installationId, $platform, $token, $name, $environment, $transport, $scope, $fingerprint): NotificationDevice {
                $identity = $this->recipients->identity($owner);
                $device = NotificationDevice::where('application', $identity['application'])->where('environment', $environment)
                    ->where('installation_id', $installationId)->lockForUpdate()->first();
                if ($device !== null) {
                    $this->assertOwner($device, $owner);
                    if ($device->token_fingerprint !== $fingerprint || $device->revoked_at !== null) {
                        $device->generation++;
                    }
                } else {
                    $device = new NotificationDevice();
                }
                $device->forceFill([...$identity, 'environment' => $environment, 'installation_id' => $installationId,
                    'platform' => $platform, 'transport' => $transport, 'transport_scope' => $scope, 'token' => $token,
                    'token_fingerprint' => $fingerprint, 'name' => $name, 'last_active_at' => now(), 'revoked_at' => null]);
                $device->save();

                return $device->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('NotificationDevice registration conflicts with an existing registration.');
        }
    }

    public function remove(Model $owner, string $deviceId): void
    {
        $this->recipients->coordinate($owner, function () use ($owner, $deviceId): void {
            $device = NotificationDevice::whereKey($deviceId)->lockForUpdate()->firstOrFail();
            $this->assertOwner($device, $owner);
            $device->forceFill(['token' => null, 'token_fingerprint' => null, 'revoked_at' => now(),
                'generation' => $device->generation + 1])->save();
        });
    }

    public function setPushEnabled(Model $owner, string $deviceId, bool $enabled): void
    {
        $this->recipients->coordinate($owner, function () use ($owner, $deviceId, $enabled): void {
            $device = NotificationDevice::whereKey($deviceId)->lockForUpdate()->firstOrFail();
            $this->assertOwner($device, $owner);
            $device->forceFill(['push_enabled' => $enabled])->save();
        });
    }

    public function reassign(Model $currentOwner, string $deviceId, Model $newOwner): NotificationDevice
    {
        $owners = [$currentOwner, $newOwner];
        usort($owners, fn (Model $a, Model $b): int => strcmp(json_encode($this->recipients->identity($a)), json_encode($this->recipients->identity($b))));

        return $this->recipients->coordinate($owners[0], fn (): NotificationDevice => $this->recipients->coordinate($owners[1], function () use ($currentOwner, $newOwner, $deviceId): NotificationDevice {
            $device = NotificationDevice::whereKey($deviceId)->lockForUpdate()->firstOrFail();
            $this->assertOwner($device, $currentOwner);
            $device->forceFill([...$this->recipients->identity($newOwner), 'generation' => $device->generation + 1,
                'push_enabled' => true, 'last_active_at' => now()])->save();

            return $device;
        }));
    }

    public function assertOwner(NotificationDevice $device, Model $owner): void
    {
        $identity = $this->recipients->identity($owner);
        if ($device->application !== $identity['application'] || $device->owner_type !== $identity['owner_type']
            || $device->owner_id !== $identity['owner_id'] || $device->environment !== config('moztopia-notifications.environment')) {
            throw new AuthorizationException('NotificationDevice registration is not owned by this recipient in the current application environment.');
        }
    }
}
