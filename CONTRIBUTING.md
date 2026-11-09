# Contributing to Moztopia Notifications

Thanks for helping make Laravel notifications reliable and easier to integrate.

## Choose the right path

- Read the [wiki](https://github.com/moztopia/moztopia-notifications/wiki) and [Agents Start Here](https://github.com/moztopia/moztopia-notifications/wiki/Agents-Start-Here) for integration.
- Ask usage and installation questions in [Q&A](https://github.com/moztopia/moztopia-notifications/discussions/categories/q-a).
- Discuss substantial ideas in [Ideas](https://github.com/moztopia/moztopia-notifications/discussions/categories/ideas) before implementation.
- Use a bug issue for a minimal reproducible package defect. Include versions, expected/actual behavior and safe diagnostics.
- Use [private vulnerability reporting](https://github.com/moztopia/moztopia-notifications/security/advisories/new) for security problems. Read [.github/SECURITY.md](.github/SECURITY.md).

Search for an existing discussion/issue before starting another. Maintainers may close duplicates, off-topic content, unsolicited advertising, repetitive demands or abusive submissions. Legitimate first-time contributors are welcome.

## Propose code changes

Fork the public repository, create a descriptive branch in your fork and open a focused PR targeting `develop`. Do not target `main` unless a maintainer explicitly requests a release/maintenance change. Repository-internal maintainer branches follow the project's issue-based branch conventions; forks do not need to copy those internal names.

For substantial changes, agree the scope first. Explain the problem and resulting behavior, reference the issue, and keep the implementation, regression tests and necessary docs together. Maintainers may ask for smaller changes or defer a feature to keep the package focused. You do not need collaborator access to submit a fork PR.

This is a Composer package, not an application scaffold. Follow the existing namespace, `Notification`-prefixed classes, `moztopia_notification_` tables and Laravel conventions. Preserve one logical notification per recipient, independent device retries, owner/generation checks, current preferences, cancellation and idempotency. Use native Laravel facilities and the existing provider channels.

## Local verification

Use a supported, security-patched PHP runtime and Composer:

```sh
composer install
composer test
composer lint
```

Tests are authored in Pest and use mocked providers. Changes must not require real Firebase/Apple credentials in automated tests. Include focused regression coverage for fixes and document verification limits. Follow the wiki's separate real-device smoke procedure when claiming device receipt, display or tap behavior.

Do not commit credentials, device tokens, generated dependency lockfiles, private application data or workstation paths. Never add scripts that fetch untrusted code with elevated tokens. Changes to workflows, dependency sources, release behavior and security-sensitive configuration require particular maintainer scrutiny.

## Review and publishing

Protected integration branches require a PR and resolved conversations. Normal review requires a fresh approval from another maintainer and applicable code-owner review. The repository owner's explicit approval is sufficient without a second maintainer, including when the owner's account authored the PR; an owner-only, PR-only exception implements that approval path. New commits invalidate stale approvals. Public users cannot merge or write package code. Maintainers control release tags and publication; sponsoring does not purchase merge access, a security exception or a support SLA.

GitHub Actions defaults to read-only tokens. Outside contributors' workflows require approval. Do not use `pull_request_target` to execute untrusted PR code with secrets, and do not ask maintainers to expose provider credentials for a reproduction. Current configured checks and required reviews determine merge eligibility; do not assume a nonexistent CI check has run.

Contributions are made under the repository's [MIT License](LICENSE). Submit only work you have permission to contribute. AI-assisted contributions are welcome when the contributor reviews and understands the result, verifies it and takes responsibility for it.
