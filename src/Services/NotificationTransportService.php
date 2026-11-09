<?php

declare(strict_types=1);

namespace Moztopia\Notifications\Services;

use GuzzleHttp\Exception\ConnectException;
use Exception;
use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Kreait\Firebase\Exception\Messaging\ApiConnectionFailed;
use Kreait\Firebase\Exception\Messaging\QuotaExceeded;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Laravel\Firebase\FirebaseProjectManager;
use Kreait\Firebase\Contract\Messaging;
use Moztopia\Notifications\Models\NotificationDevice;
use Moztopia\Notifications\Notifications\NotificationPushMessage;
use NotificationChannels\Apn\ApnAdapter;
use NotificationChannels\Apn\ApnChannel;
use NotificationChannels\Fcm\FcmChannel;
use Pushok\AuthProvider\Token;
use Pushok\Client;
use Throwable;

class NotificationTransportService
{
    public function send(NotificationDevice $device, NotificationPushMessage $notification): array
    {
        try {
            $events = new Dispatcher(app());
            $notifiable = new AnonymousNotifiable();
            if ($device->transport === 'fcm') {
                $project = config('moztopia-notifications.fcm_project');
                if (array_filter(config('firebase.projects.'.$project.'.logging', []))) {
                    return $this->failure('unsafe_http_logging', false);
                }
                $client = $this->fcmClient();
                $channel = new FcmChannel($events, $client);
                $reports = $channel->send($notifiable->route('fcm', $device->token), $notification);
                $items = $reports?->first()?->getItems() ?? [];
                if (count($items) !== 1) {
                    return $this->failure('missing_provider_result', true);
                }
                if ($items[0]->isSuccess()) {
                    return ['state' => 'provider_accepted'];
                }

                return $this->classify($items[0]->error());
            }

            $options = config('moztopia-notifications.apns');
            $production = (bool) ($options['production'] ?? true);
            if (($device->environment === 'production') !== $production) {
                return $this->failure('apns_environment_mismatch', false);
            }
            $notification->withApnsClient($this->apnsClient());
            $channel = new ApnChannel(app(), $events, new ApnAdapter());
            try {
                $responses = $channel->send($notifiable->route('apn', $device->token), $notification);
            } catch (Exception $error) {
                if (get_class($error) === Exception::class) {
                    return $this->failure('apns_connection_failed', true);
                }

                return $this->classify($error);
            }
            if (count($responses ?? []) !== 1) {
                return $this->failure('missing_provider_result', true);
            }
            $response = $responses[0];
            $status = $response->getStatusCode();
            if ($status === 200) {
                return ['state' => 'provider_accepted'];
            }
            if ($status === 410 && $response->getErrorReason() === 'Unregistered') {
                return [...$this->failure('unregistered_token', false), 'invalid_token' => true];
            }

            return [...$this->failure('apns_http_'.$status, $status === 429 || $status >= 500 || $status === 0),
                'retry_after' => $status === 429 ? 60 : 0];
        } catch (Throwable $error) {
            return $this->classify($error);
        }
    }

    public function classify(?Throwable $error): array
    {
        if ($error instanceof MessagingException) {
            foreach (($error->errors()['error']['details'] ?? $error->errors()['details'] ?? []) as $detail) {
                if (($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                    return [...$this->failure('unregistered_token', false), 'invalid_token' => true];
                }
            }
        }
        if ($error instanceof QuotaExceeded || $error instanceof ServerUnavailable) {
            return [...$this->failure('provider_throttled', true),
                'retry_after' => max(0, ($error->retryAfter()?->getTimestamp() ?? 0) - time())];
        }
        if ($error instanceof ServerError || $error instanceof ApiConnectionFailed || $error instanceof ConnectException) {
            return $this->failure('provider_unavailable', true);
        }

        return $this->failure('provider_rejected_or_configuration_error', false);
    }

    protected function fcmClient(): Messaging
    {
        return app(FirebaseProjectManager::class)->project(config('moztopia-notifications.fcm_project'))->messaging();
    }

    protected function apnsClient(): Client
    {
        $options = config('moztopia-notifications.apns');
        $production = (bool) $options['production'];
        unset($options['production']);

        return new Client(Token::create(array_filter($options)), $production, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20]);
    }

    private function failure(string $code, bool $retryable): array
    {
        return ['state' => $retryable ? 'retryable_failure' : 'permanent_failure', 'error_code' => $code];
    }
}
