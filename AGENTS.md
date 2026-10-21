# AGENTS.md

This file provides guidance to coding agents when working with code in this repository.

## Project Overview

Laravel queue driver for Google Cloud Pub/Sub (composer: PHP `>=7.2|^8.0`, `illuminate/queue` and `illuminate/support` `^11.0|^12.0|^13.0`, `google/cloud-pubsub` `^1.1`). Registers a `pubsub` queue connector: pushing publishes to a topic, `queue:work` pulls from a subscription. No config file is shipped; connection config lives in the consumer's `config/queue.php`.

## Commands

There is no Makefile and no Docker setup; run on the host:

```bash
composer update --ignore-platform-reqs   # composer.lock is gitignored; CI also runs composer update
vendor/bin/phpunit                       # or: composer test
vendor/bin/phpunit --filter testPopDoesNotAcknowledgeWhenOptedOut
```

CI (`.github/workflows/test.yaml`) runs PHPUnit only, on PHP 8.4, for pull requests. There is no PHPStan, phpcs or Pint. `.travis.yml` is a dead upstream leftover.

## Architecture

- `PubSubQueueServiceProvider` (auto-discovered) - registers the `pubsub` connector and, in console, the `pubsub:consume` command
- `Connectors\PubSubConnector` - camelCases all config keys and passes them to `PubSubClient`; reads `queue` (default `default`), `subscriber` (default `subscriber`), `create_topics` / `create_subscriptions` (default `true`), `queue_prefix` (default `''`), `return_immediately` (default `true`)
- `PubSubQueue` - push/pushRaw/later/bulk/pop, `acknowledge()`, `modifyAckDeadline()`, `republish()`, topic and subscription auto-creation
- `Jobs\PubSubJob` - wraps a Pub/Sub `Message`; `getAttributes()`, `getPayloadAsArray()`, `delete()`, `release()`, `extendLease()`
- `Commands\PubSubConsume` - `pubsub:consume {sub-name}` sets `queue.connections.pubsub.subscriber` and calls `queue:work`

Behaviour that is easy to get wrong:
- Queue name is the topic name; `queue_prefix` is prepended unless already present
- Ack mode: by default `pop()` acks on pull (lossy). `acknowledgeOnPop(false)` moves the ack to `PubSubJob::delete()`; `extendLease($seconds)` calls `modifyAckDeadline` (Pub/Sub caps at 600s)
- `release()` republishes a copy with an incremented `attempts` attribute (and acks the original when not acking on pop); `attempts()` reads that attribute
- Delays are an `available_at` attribute: `pop()` returns `null` for a not-yet-due message, after already acking it in the default mode
- `size()`, `pendingSize()`, `delayedSize()`, `reservedSize()` return `0`
- With `create_topics` on, every `pushRaw()` makes an `exists()` call on the topic

## Test Conventions

- Tests live in `tests/Unit/` and files end in `Tests.php` (phpunit.xml.dist matches `suffix="Tests.php"`); a `*Test.php` file will not run
- Plain `PHPUnit\Framework\TestCase` with `test` method prefix, no attributes, no Testbench
- Mock Google classes with PHPUnit's `createMock()` / `getMockBuilder()`; no Mockery
- `phpunit.xml.dist` still uses the PHPUnit 9 schema, so deprecation notices in the output are expected

## Code Style

- StyleCI `preset: laravel`; `.editorconfig`: 4 spaces, LF
- Namespace is `Kainxspirits\PubSubQueue\`; no `declare(strict_types=1)`
- Older classes use docblock types and no return types; the provider and command use typed signatures. Match the file you are in

## Fork Notes

- Hostinger fork (`hostinger/laravel-pubsub-queue`, default branch `master`). GitHub parent is `rytisder/laravel-pubsub-queue`, itself a fork of `kainxspirits/laravel-pubsub-queue`; there is no upstream remote and upstream changes are not merged in
- Additions over upstream: `pubsub:consume`, `topic` message attribute, `PubSubJob::getAttributes()` / `getPayloadAsArray()`, optional job ID, configurable `return_immediately`, Laravel 11-13 support, `acknowledgeOnPop()` / `extendLease()` (v1.2.0)
- The composer package name is still `kainxspirits/laravel-pubsub-queue`. Consumers must add `{"type": "vcs", "url": "https://github.com/hostinger/laravel-pubsub-queue"}` to `repositories`, otherwise Composer silently installs the upstream package from Packagist. Renaming the package or namespace breaks every consumer
- Releases are `vX.Y.Z` tags with the `VERSION` file bumped to match; versions start at `v1.0.0` to outrank upstream's `0.x`. Tags `0.8.0`-`0.10.0` are upstream tags, not fork history
- Main consumer is `hostinger/event-bus-client-laravel` (`^1.2`): its `PubSubWorker` extends `Illuminate\Queue\Worker` and calls `acknowledgeOnPop(false)`, `$job->extendLease()` and `$job->getAttributes()`; its `PubSubEventBusListener` calls `PubSubConnector::connect()`; its configs set `create_topics` / `create_subscriptions` to `false`. Keep these signatures and defaults backwards compatible
