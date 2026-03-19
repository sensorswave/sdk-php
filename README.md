# SensorsWave PHP SDK

This repository contains the private PHP SDK for SensorsWave event tracking
and A/B testing. The implementation follows the Go SDK's behavior model and
keeps the A/B evaluator, signing flow, sticky handling, exposure logging, and
metadata refresh semantics aligned with the Go reference.

## Requirements

You need PHP 8.2 or later and Composer.

## Install dependencies

Because this repository is private and not published to Packagist yet, work
from source:

```bash
composer install
```

## Quick start

Create a client with a collector endpoint and a source token:

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

$client->trackEvent(
    $user,
    'PageView',
    ['page' => '/home'],
);

$client->flush();
$client->close();
```

## User identity rules

For all methods except `identify`, you must provide at least one of
`anonId` or `loginId`.

If both IDs are present, the SDK uses `loginId` as the evaluation identity.

For `identify`, you must provide both `anonId` and `loginId`.

## Event tracking API

The tracking client buffers events in memory and flushes them as JSON arrays.
The SDK flushes automatically when the batch reaches 50 events or when you
call `flush()` or `close()`. In short-lived PHP processes, call `flush()` or
`close()` before exit to drain the pending batch.

The client currently supports these tracking methods:

- `identify(User $user): void`
- `flush(): void`
- `trackEvent(User $user, string $eventName, array|Properties $properties = []): void`
- `track(Event $event): void`
- `profileSet(User $user, array|Properties $properties): void`
- `profileSetOnce(User $user, array|Properties $properties): void`
- `profileIncrement(User $user, array|Properties $properties): void`
- `profileAppend(User $user, array|ListProperties $properties): void`
- `profileUnion(User $user, array|ListProperties $properties): void`
- `profileUnset(User $user, string ...$propertyKeys): void`
- `profileDelete(User $user): void`

For PHP-first usage, you can pass plain arrays to all event and user property
helpers. Use `Properties` or `ListProperties` only when you want the fluent
builder API.

### Retry and failure handling

Tracking requests retry on transport errors and non-200 responses according to
`httpRetry`.

If the final attempt still fails, the SDK logs the failure and invokes
`onTrackFailHandler`, if configured. The callback receives the decoded event
batch, the thrown exception when present, and the final HTTP status code when
available.

Available tracking config fields include:

- `trackUriPath`: tracking path, default `/in/track`
- `flushIntervalMs`: opportunistic flush interval in milliseconds
- `httpRetry`: retry count for tracking requests
- `onTrackFailHandler`: failure callback for decoded event batches

## A/B testing

The SDK supports feature gates, feature configs, experiments, sticky results,
automatic exposure logging, preloaded metadata, remote metadata loading, and
on-demand metadata refresh.

### Evaluate a gate, config, or experiment

Use `ABConfig` to enable A/B capabilities:

```php
<?php

declare(strict_types=1);

use SensorsWave\Client\Client;
use SensorsWave\Config\ABConfig;
use SensorsWave\Config\Config;
use SensorsWave\Model\User;

$client = Client::create(
    'https://collector.example.com',
    'your-source-token',
    new Config(
        ab: new ABConfig(
            projectSecret: 'your-project-secret',
        ),
    ),
);

$user = new User(loginId: 'user-123');

$gatePassed = $client->checkFeatureGate($user, 'my_gate');
$config = $client->getFeatureConfig($user, 'my_config');
$experiment = $client->getExperiment($user, 'my_experiment');
$allResults = $client->evaluateAll($user);
```

### Read variant payloads

`ABResult` provides the same helper shape as the Go SDK:

- `getString(string $key, string $fallback): string`
- `getNumber(string $key, float $fallback): float`
- `getBool(string $key, bool $fallback): bool`
- `getSlice(string $key, array $fallback): array`
- `getMap(string $key, array $fallback): array`
- `jsonPayload(): string`

### Preload cached A/B specs

You can start with cached metadata instead of hitting the remote metadata
endpoint on startup:

```php
<?php

declare(strict_types=1);

use SensorsWave\Client\Client;
use SensorsWave\Config\ABConfig;
use SensorsWave\Config\Config;

$cachedSpecs = file_get_contents(__DIR__ . '/ab-specs.json');

$client = Client::create(
    'https://collector.example.com',
    'your-source-token',
    new Config(
        ab: new ABConfig(
            loadABSpecs: $cachedSpecs ?: '',
        ),
    ),
);
```

### Export cached A/B specs

Use `getABSpecs()` to export the current in-memory metadata snapshot:

```php
$snapshot = $client->getABSpecs();
file_put_contents(__DIR__ . '/ab-specs.json', $snapshot);
```

### Refresh remote metadata

If `projectSecret` is configured, the client creates a signed metadata loader.
When `metaLoadIntervalMs` elapses, the next A/B evaluation triggers a refresh.

Available A/B config fields:

- `projectSecret`: required for remote metadata loading
- `metaEndpoint`: overrides the collector endpoint for A/B metadata
- `metaUriPath`: metadata path, default `/ab/all4eval`
- `metaLoadIntervalMs`: refresh interval in milliseconds
- `stickyHandler`: sticky result storage implementation
- `loadABSpecs`: preloaded metadata snapshot

## Lower-level evaluator

`ABCore` is available as a lower-level evaluator if you want to work from a
loaded storage snapshot directly.

Current public methods include:

- `evaluate(User $user, string $key, ?int $type = null): ABResult`
- `evaluateAll(User $user): array`
- `getABSpecs(): string`

At the client layer, you can also call `evaluateAll(User $user): array` to
fetch all current results and emit impression events for each returned spec.

## Development

Run the test suite:

```bash
vendor/bin/phpunit
```

Run static analysis:

```bash
vendor/bin/phpstan analyse --memory-limit=512M
```

## Current status

The repository currently includes:

- Tracking models, serialization, and transport wiring
- ACS3-HMAC-SHA256 request signing
- Feature gate, config, and experiment evaluation
- Sticky result read and write support
- Automatic exposure event construction
- Remote metadata loading and on-demand refresh
- Snapshot export and snapshot import compatibility

## Next steps

If you extend the SDK further, keep behavior aligned with the Go fixtures and
tests under `tests/Fixtures/ab` and `tests/ABTesting`.
