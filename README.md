# SensorsWave PHP SDK

SensorsWave PHP SDK uses a local-only request runtime. Request-path code reads
A/B snapshots from a local store and appends tracking data to a local queue. A
separate worker process performs remote metadata sync and event delivery.

## Features

- Local-only request runtime for PHP/FPM
- Event tracking with automatic `$lib` and `$lib_version` injection
- User profile operations: set, set once, increment, append, union, unset, delete
- ACS3-HMAC-SHA256 request signing for worker-side metadata sync
- A/B feature gate, config, and experiment evaluation from local snapshots
- Automatic exposure logging queued after A/B evaluation
- Default local file adapters, with Redis adapter support through abstractions

## Installation

This repository is private and not published to Packagist yet.

```bash
composer install
```

## Runtime model

The PHP SDK does not perform remote I/O on the request path.

- `Client` reads A/B snapshots from `ABSpecStore`
- `Client` writes tracking and impression payloads to `EventQueue`
- `sensorswave-sync` pulls remote metadata and saves snapshots
- `sensorswave-send` reads queued events and sends them to the collector

By default, the SDK uses local file adapters under `sys_get_temp_dir()`. You
can replace them with Redis-backed adapters by implementing the Redis client
abstraction.

## Quick start

### 1. Create the client

```php
<?php

declare(strict_types=1);

use SensorsWave\Client\Client;
use SensorsWave\Model\User;

$client = Client::create(
    'https://collector.example.com',
    'your-source-token',
);

$user = new User(anonId: 'device-123', loginId: 'user-123');

$client->trackEvent($user, 'PageView', [
    'page' => '/home',
]);

$client->close();
```

### 2. Sync A/B snapshots out of band

Run the sync worker on a schedule.

```bash
./bin/sensorswave-sync https://collector.example.com your-source-token your-project-secret
```

### 3. Send queued events out of band

Run the send worker on a schedule.

```bash
./bin/sensorswave-send https://collector.example.com your-source-token
```

## A/B behavior

The PHP client only evaluates from local snapshots.

- If a valid snapshot exists, the client evaluates gates, configs, and experiments locally
- If the snapshot is missing or stale, gate checks fail closed
- The client never falls back to remote metadata refresh on the request path

## API reference

### Client construction

| Method | Description |
|--------|-------------|
| `Client::create(string $endpoint, string $sourceToken, ?Config $config = null): Client` | Create a client with required endpoint and source token |

### Lifecycle

| Method | Description |
|--------|-------------|
| `close(): void` | Flush in-memory events into the local queue and close the client |
| `flush(): void` | Flush the current buffered batch into the local queue without closing |

### User identity

| Method | Description |
|--------|-------------|
| `identify(User $user): void` | Link anonymous ID with login ID; both are required |

### Event tracking

| Method | Description |
|--------|-------------|
| `trackEvent(User $user, string $eventName, array|Properties $properties = []): void` | Track a named event with properties |
| `track(Event $event): void` | Track a fully constructed event |

### User profile operations

| Method | Description |
|--------|-------------|
| `profileSet(User $user, array|Properties $properties): void` | Set user properties |
| `profileSetOnce(User $user, array|Properties $properties): void` | Set user properties only if they do not exist |
| `profileIncrement(User $user, array|Properties $properties): void` | Increment numeric user properties; non-numeric values are ignored |
| `profileAppend(User $user, array|ListProperties $properties): void` | Append values to list properties |
| `profileUnion(User $user, array|ListProperties $properties): void` | Append unique values to list properties |
| `profileUnset(User $user, string ...$propertyKeys): void` | Remove user properties |
| `profileDelete(User $user): void` | Delete the whole user profile |

### A/B testing

| Method | Description |
|--------|-------------|
| `checkFeatureGate(User $user, string $key): bool` | Evaluate a feature gate from the local snapshot |
| `getFeatureConfig(User $user, string $key): ABResult` | Evaluate a feature config from the local snapshot |
| `getExperiment(User $user, string $key): ABResult` | Evaluate an experiment from the local snapshot |
| `evaluateAll(User $user): array` | Evaluate all currently loaded specs and queue impressions |
| `getABSpecs(): string` | Export the current A/B metadata snapshot |

## Configuration options

### Client config

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `trackUriPath` | string | `/in/track` | Event delivery path used by the send worker |
| `flushIntervalMs` | int | `10000` | In-memory batch rollover interval |
| `httpConcurrency` | int | `1` | Worker-side request concurrency setting |
| `httpTimeoutMs` | int | `3000` | Worker-side request timeout |
| `httpRetry` | int | `2` | Worker-side retry attempts |
| `eventQueue` | `EventQueueInterface` | local file queue | Queue used by request-path tracking APIs |
| `onTrackFailHandler` | callable | `null` | Failure callback when queue writes fail |
| `ab` | `?ABConfig` | `null` | A/B configuration |
| `transport` | `?TransportInterface` | `null` | Worker-side custom transport implementation |
| `logger` | `?LoggerInterface` | default logger | Custom logger |

### ABConfig

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `projectSecret` | string | `''` | Project secret used by the sync worker |
| `metaEndpoint` | string | main endpoint | Metadata endpoint override for the sync worker |
| `metaUriPath` | string | `/ab/all4eval` | Metadata request path |
| `metaLoadIntervalMs` | int | `60000` | Snapshot freshness threshold; minimum `30000` |
| `stickyHandler` | `?StickyHandlerInterface` | `null` | Sticky assignment storage |
| `loadABSpecs` | string | `''` | Bootstrap snapshot payload |
| `abSpecStore` | `ABSpecStoreInterface` | local file store | Snapshot store used by the request path |

## Default adapters

The SDK ships with these default implementations:

- `LocalFileABSpecStore`
- `LocalFileEventQueue`
- `RedisABSpecStore`
- `RedisEventQueue`

Redis-backed adapters depend on `RedisClientInterface`, so you can wire the SDK
to your preferred Redis extension or client library without introducing a hard
dependency.

## Development

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

## Status

The repository currently implements:

- `client-sdk-core`
- `request-signing-acs3`
- `tracking-core`
- `user-profile-ops`
- `ab-core-evaluation`
- `ab-meta-snapshot-bootstrap`

## License

Private / proprietary repository.
