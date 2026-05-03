# Security Policy

## Supported Versions

Security fixes are provided for the latest minor release of the current major version.

| Version | Supported          |
| ------- | ------------------ |
| 3.x     | :white_check_mark: |
| < 3.0   | :x:                |

Older major versions may receive fixes for critical vulnerabilities at the maintainer's discretion, but users are encouraged to upgrade.

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues, discussions, or pull requests.**

Report vulnerabilities privately via [GitHub private vulnerability reporting](https://github.com/maxbanton/cwh/security/advisories/new). This opens a private advisory visible only to the maintainers.

Please include:

- A description of the vulnerability and its potential impact.
- Steps to reproduce, including a minimal code sample if possible.
- The affected version(s) of `maxbanton/cwh`.
- Any known mitigations or workarounds.

## Response Expectations

- **Acknowledgement:** within 5 business days.
- **Initial assessment:** within 10 business days.
- **Fix and disclosure:** coordinated with the reporter; typical target is 90 days from report, sooner for actively exploited issues.

Reporters will be credited in the published advisory unless they request otherwise.

## Scope

This policy covers the `maxbanton/cwh` package source code published on Packagist and this repository. Vulnerabilities in upstream dependencies (`monolog/monolog`, `aws/aws-sdk-php`) should be reported to those projects directly.
