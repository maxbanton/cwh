# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.0] - 2026-04-26

### Added

- Public `flush()` method for long-lived workers (Laravel queues, Symfony messenger, PHP-FPM with persistent state). The handler's `reset()` is also overridden to flush, integrating with Symfony `ResettableInterface` and Laravel Octane's `FlushMonologState`. (#95, thanks @DrLuke for #102)
- README section documenting ECS / EC2 IAM Task Role credentials with `CredentialProvider::memoize()`. (#103)
- Docker-based local dev environment (`make build`, `make test`, `make matrix`) running against PHP 8.2 / 8.3 / 8.4 / 8.5.
- CI runs entirely in Docker containers; only `actions/checkout` and `actions/upload-artifact` (both official) are used.
- `.gitattributes` excludes dev files from the Composer dist tarball; `composer require maxbanton/cwh` now ships only `LICENSE`, `README.md`, `composer.json`, and `src/Handler/CloudWatch.php` (~57% smaller).

### Changed

- `EVENT_SIZE_LIMIT` raised from 256 KB to **1 MB (1,048,550 bytes)** to match the current AWS PutLogEvents quota. Messages between 256 KB and 1 MB are now shipped as a single CloudWatch event instead of split. (#101)
- Log group / stream initialization now uses idempotent create-and-swallow-`ResourceAlreadyExistsException` pattern instead of pre-flight `describeLogGroups` / `describeLogStreams`. Removes 2 AWS API calls per process startup and shrinks the required IAM permissions. (#86, #120)
- `$retention` constructor parameter is now typed as `?int`. (thanks @vinicius-venngage for #105)

### Removed

- `RPS_LIMIT` self-throttle (5 RPS). The library no longer sleeps to artificially cap PutLogEvents calls — the AWS quota is 5,000 TPS per account/region (1000× higher), and the AWS SDK's retry middleware handles real throttling automatically. (#111, #114)
- `sequenceToken` tracking. AWS deprecated this parameter in August 2023 — `PutLogEvents` ignores it server-side and never throws `InvalidSequenceTokenException` or `DataAlreadyAcceptedException` anymore. (thanks @a10waveracer for #115)
- The retry-on-`CloudWatchLogsException` loop in `flushBuffer()` (it existed solely to refresh the sequence token, which no longer matters).
- Required IAM permissions `logs:DescribeLogStreams` and `logs:DescribeLogGroups`. Existing policies that still grant these permissions remain compatible — extra permissions are harmless. (#114)

### Fixed

- `checkThrottle()` had a latent bug — `$current->diff($savedTime)->s` reads only the seconds component of a DateInterval, misbehaving across minute boundaries. Fixed by removing the method entirely (see "Removed" above).

### Compatibility

- **No breaking public API changes.** Adds public `flush()` and overrides `reset()` to flush. Existing constructor and method signatures are unchanged; existing `^2.0` pins continue to work without modification.
- Minimum PHP / Monolog versions unchanged (`^7.2 || ^8` / `monolog/monolog: ^2.0`). CI tests PHP 8.2 – 8.5; no code paths require newer PHP.

[Unreleased]: https://github.com/maxbanton/cwh/compare/v2.1.0...HEAD
[2.1.0]: https://github.com/maxbanton/cwh/compare/v2.0.4...v2.1.0
