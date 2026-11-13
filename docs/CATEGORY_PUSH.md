# Category push preferences and Android channels

Version 0.9.2 extends the original Whisper 0.9.0 contract for [issue #14](https://github.com/moztopia/moztopia-notifications/issues/14), following [discussion #13](https://github.com/moztopia/moztopia-notifications/discussions/13). It also includes the MariaDB index compatibility fix from 0.9.1.
## Consumer usage

Applications choose their own category keys, visible labels and Android channel IDs. For example, a consumer can preserve visits in history while allowing its member to mute message push independently:

```php
use Moztopia\Notifications\Services\NotificationPreferenceService;

app(NotificationPreferenceService::class)->update(
    owner: $member,
    pushCategories: ['messages' => false, 'matches' => true],
);
```

The returned preference model exposes `push_categories` as a boolean map. Add the application-owned settings to its published `config/moztopia-notifications.php`:

```php
'push_categories' => [
    'visits' => false,
],
'android_channels' => [
    'messages' => 'chat_messages',
    'matches' => 'mutual_matches',
],
```

The first mapping is a server policy ceiling: a member cannot override `visits => false` by enabling that category in their push preferences. An empty map keeps the original behavior. Entries must map category keys to booleans. These are application configuration, not arbitrary client request fields.

The Android app must create `chat_messages` and `mutual_matches` channels with the desired visible names/settings before receiving notifications. The package sets `android.notification.channel_id` in the actual FCM message, so background OS presentation can select the channel without waiting for Flutter. Unmapped categories omit this setting and retain FCM's existing default behavior. Configured channel IDs must be nonempty strings. Android channel settings and OS permission may still suppress delivery/presentation; the package does not create device channels or override their settings. APNs behavior is unchanged.

## Preference semantics

| Setting | History | Push |
| --- | --- | --- |
| `enabled: false` | Suppressed | Suppressed |
| `categories: ['messages' => false]` | Messages suppressed | Messages suppressed |
| `pushEnabled: false` | Preserved | All push suppressed |
| `pushCategories: ['messages' => false]` | Preserved | Only message push suppressed |
| Config `push_categories: ['visits' => false]` | Preserved | Visit push always suppressed |
| Device push disabled | Preserved | That device suppressed |

Missing category keys default to allowed, subject to every other eligibility check. `null` leaves a preference map unchanged; a supplied map replaces that map, and `[]` clears its overrides. `categories` retains its original meaning. Appending the optional `pushCategories` argument preserves existing positional and named calls.

Send and worker/retry checks use `NotificationPreference::allowsPush()`. A delivery queued before a member mutes its category is skipped at its next attempt; its history remains intact. The existing recipient coordination also serializes preference changes with delivery attempts. A push already accepted by a provider cannot be recalled, and configuration changes require the normal worker restart/config-cache process. Re-enabling alerts affects new events; it does not recreate skipped deliveries or send old history. Repeating an idempotency key still returns the original event. Keep the same event ID for history, live updates and push deduplication.

## Installation and upgrade

Install the numeric package version through public Packagist:

```sh
composer require 'moztopia/notifications:^0.9.2'
```

Consumers of the temporary development branch should replace their branch/hash constraint with the numeric constraint above. A custom VCS repository is not required for published versions; remove the temporary moztopia-notifications repository entry if it was added only for this integration. Verify the resolved version and follow the migration steps below. Existing immutable tags remain unchanged.

For a fresh installation, publish the package migrations and apply them normally; both migrations are required. For an existing installation, retain its applied initial migration and copy only `database/migrations/2026_11_12_000001_add_push_categories_to_moztopia_notification_preferences.php` from the installed package into the host's migrations directory, then run `php artisan migrate`. Do not republish the initial schema under a new timestamp, edit its migration record, or reset the database. The nullable new column preserves every existing preference and history row. Publish/merge the two new empty config maps as needed and restart long-running workers through the host's normal rollout process.

Rolling back the additive migration removes only the new preference column, losing its push-category overrides. Deploy the old compatible package code and stop/restart workers as part of a coordinated rollback; new code must not continue writing that column after removal. Application-only policy/channel configuration is independent of this schema rollback.

## Verification limits

Pest covers multi-device history preservation, default and legacy settings, preference validation/ownership, queued and retry-time opt-outs, policy ceilings, additive migration/rollback, idempotency and actual FCM SDK payload serialization. Provider calls remain mocked. The independent-connection test uses SQLite; it does not establish MariaDB queue/concurrency behavior. Android/iOS receipt, display, channel behavior and taps still require consumer device tests. No credentials are required by this suite.
