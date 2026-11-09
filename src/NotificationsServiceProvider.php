<?php

declare(strict_types=1);

namespace Moztopia\Notifications;

use Illuminate\Support\ServiceProvider;
use Moztopia\Notifications\Console\NotificationCleanupCommand;
use Moztopia\Notifications\Console\NotificationRecoverCommand;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/moztopia-notifications.php', 'moztopia-notifications');
    }

    public function boot(): void
    {
        $project = config('moztopia-notifications.fcm_project');
        if (config('moztopia-notifications.fcm_credentials') !== null) {
            config(['firebase.projects.'.$project.'.credentials' => config('moztopia-notifications.fcm_credentials')]);
        }
        config(['firebase.projects.'.$project.'.http_client_options.timeout' => config('moztopia-notifications.fcm_timeout')]);
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/moztopia-notifications.php' => config_path('moztopia-notifications.php')], 'moztopia-notifications-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'moztopia-notifications-migrations');
            $this->commands([NotificationRecoverCommand::class, NotificationCleanupCommand::class]);
        }
    }
}
