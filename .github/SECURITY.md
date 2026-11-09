# Security policy

## Report privately

Use [GitHub private vulnerability reporting](https://github.com/moztopia/moztopia-notifications/security/advisories/new) for security issues. Do not open a public issue or Discussion containing an exploit, credential, device token or personal data.

Include the affected package version/commit, impact, minimal reproduction with dummy data and any safe suggested mitigation. Relevant areas include cross-owner access, stale delivery/token races, leaked credentials, queue payload exposure and dependency vulnerabilities. If a credential was exposed, revoke it promptly through its provider; do not paste it into a report.

The maintainers will assess reports privately and coordinate a fix and disclosure. No response-time SLA, paid bounty or production support contract is implied. If you discover a vulnerability in a dependency, also follow that project's disclosure policy and explain the package impact without publishing an exploit here.

## Supported versions

No stable version has been tagged yet. Security reports against the current `develop` branch are welcome. The planned `0.9.0` release is not available until its tag is published. Supported release lines will be announced with releases; do not assume an unmaintained fork receives security fixes.

Automated provider-mocked tests are not proof of actual device delivery. Keep sensitive content out of push payloads and verify integration in the consuming environment.
