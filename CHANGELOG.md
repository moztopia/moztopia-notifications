# Changelog

This file records package changes by numeric version. Git tags identify the source; [GitHub Releases](https://github.com/moztopia/moztopia-notifications/releases) contain publication notes. A version tag does not establish real-device delivery or production readiness.

## Unreleased — category push integration branch

- Add optional per-recipient `pushCategories` preferences, independently of history/category eligibility and the global push switch.
- Add application `push_categories` policy for categories that must never send push; evaluate it and current preferences at creation and every delivery attempt.
- Add category-to-Android-channel mapping through `android_channels`, emitted as FCM `android.notification.channel_id`.
- Add a nullable `push_categories` preference column through a separate reversible migration. Existing rows and omitted mappings preserve Whisper behavior.
- Retain event identity, history, per-device delivery tracking, retries and direct APNs. Re-enabling alerts does not replay suppressed events.

This work is held on `wip/0/14-category-push-and-android-channels` for consumer integration and author coordination. VERSION and the immutable 0.9.0 tag remain unchanged until an accepted integration/release. See [branch usage, migration and compatibility](docs/CATEGORY_PUSH.md). Real-device verification remains pending.

## [0.9.0] — Whisper

Whisper is the first named release of `moztopia/notifications`. It formalizes the package implemented in the `0.0.2` integration checkpoint. Notification runtime behavior and database schema are unchanged from that checkpoint.

### Release changes

- Introduce this root changelog as the source for release notes.
- Set the package source version to `0.9.0`.
- Update public Composer installation and security-policy wording to reflect published numeric versions.

### Package capabilities

- Android FCM and direct iOS APNs delivery through the established Laravel notification channels.
- One logical notification/history record per recipient and an independent queued delivery lifecycle per eligible device.
- UUIDv7 device/message/delivery identities, polymorphic UUID recipients, encrypted tokens, scoped keyed fingerprints and generation-safe rotation/removal/reassignment.
- Application/recipient event idempotency, retained deduplication metadata, current preferences and cancellation-aware history deletion.
- Native Laravel database-notification read/unread behavior in `moztopia_notification_history`.
- Laravel queues, bounded retries/backoff/TTL, safe provider error classification, invalid-token cleanup and recovery after enqueue outages.
- Public wiki, Flutter integration contract, agent integration guide and separate real-device smoke procedure.
- Public contribution templates, private vulnerability reporting, sponsorship and repository governance.

### Requirements and installation

Laravel 12 or 13, PHP 8.4.27+ or 8.5.12+, UUID recipient models and a shared transactional database connection. Production integrations need their own transport credentials, durable Laravel queue, workers and scheduler.

```sh
composer require moztopia/notifications:0.9.0
php artisan vendor:publish --tag=moztopia-notifications-config
php artisan vendor:publish --tag=moztopia-notifications-migrations
php artisan migrate
```

The package is available through public Packagist; no private GitHub authentication or custom Composer repository is required. See the [installation guide](https://github.com/moztopia/moztopia-notifications/wiki/Installation) and [agent guide](https://github.com/moztopia/moztopia-notifications/wiki/Agents-Start-Here).

### Upgrade from 0.0.2

```sh
composer require moztopia/notifications:0.9.0
```

No additional schema migration or configuration-key change is introduced by Whisper. Preserve the already applied initial migration and existing data; do not republish it under a new timestamp or reset the database. Restart long-running queue workers through the consuming application's normal process.

### Validation and limits

- Provider-mocked Pest coverage includes 47 tests and 200 assertions across Laravel 12/13 and PHP 8.4/8.5, including an independent-connection SQLite concurrency check. Composer validation, Pint and installation/provider-discovery checks are documented in the [testing guide](https://github.com/moztopia/moztopia-notifications/wiki/Testing).
- Real Android/iOS receipt, display and tap handling remain **unverified**. Run the consuming application's [device smoke tests](https://github.com/moztopia/moztopia-notifications/wiki/Testing) before claiming successful production delivery.
- Actual consuming database engines, durable queue drivers and Flutter client integration require environment-specific verification; SQLite/provider mocks do not prove those integrations.
- Provider acceptance is not a device receipt. Exactly-once device delivery is not guaranteed after ambiguous network/commit failures; use stable IDs and client deduplication.
- Calls for devices of the same recipient are serialized through database coordination. Fingerprint-key rotation requires coordinated maintenance. Receipt acknowledgments are deferred.

## [0.0.2] — Integration checkpoint

First published numeric source tag. Included the Laravel package implementation, credential-free Pest suite, public Packagist registration and implementation wiki, plus public repository contribution/security guidance, templates, funding and owner approval policy.

The earlier initial implementation carried source version `0.0.1` without a published numeric tag. `0.0.2` is an integration checkpoint and did not establish real-device delivery verification.

[0.9.0]: https://github.com/moztopia/moztopia-notifications/compare/0.0.2...0.9.0
[0.0.2]: https://github.com/moztopia/moztopia-notifications/tree/0.0.2
