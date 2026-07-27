# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-07-27
### Added:
- Configurable batch pulling: `pull_max_messages` pulls up to N messages per request and buffers the surplus in-memory, handing out one job per `pop()` call (default 1, backward compatible)
- `max_buffer_age` guard: buffered messages older than the configured age are dropped unacked so PubSub redelivers them (avoids duplicate processing after ack-deadline expiry)
- Pull timeouts (relevant with `return_immediately: false`) are treated as empty pulls instead of crashing the worker loop
- Company CI setup: test workflow with Docker image build, coverage reporting, Dependabot with auto-merge, CODEOWNERS
- PHPStan (level 6) and Laravel Pint configured, not yet enforced in CI

### Fixed:
- `$decoded` dynamic property deprecation in `PubSubJob` (PHP 8.2+)

### Updated:
- Test suite migrated to PHPUnit 11/12 (`setMethods` -> `onlyMethods`)

## [1.0.0] - 2026-07-27
### Added:
- Baseline release freezing the long-standing `dev-master` state, so consumers can move to tagged versions
