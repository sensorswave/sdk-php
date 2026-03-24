# SensorsWave PHP SDK

A lightweight PHP SDK for SensorsWave event tracking and A/B testing.

## Features

- Event tracking with automatic `$lib` and `$lib_version` injection
- User profile operations: set, set once, increment, append, union, unset, delete
- ACS3-HMAC-SHA256 request signing
- A/B feature gate, config, and experiment evaluation
- Automatic exposure logging after A/B evaluation
- Remote metadata loading, refresh, and snapshot bootstrap

## Installation

This repository is private and not published to Packagist yet.

```bash
composer install
```

## Quick Start

### Basic Event Tracking

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

$client->flush();
$client->close();
```

### Enable A/B Testing

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
```

## API Reference

### Client Construction

| Method | Description |
|--------|-------------|
| `Client::create(string $endpoint, string $sourceToken, ?Config $config = null): Client` | Create a client with required endpoint and source token |

### Lifecycle

| Method | Description |
|--------|-------------|
| `close(): void` | Flush remaining events and close the client |
| `flush(): void` | Flush the current buffered batch without closing |

### User Identity

| Method | Description |
|--------|-------------|
| `identify(User $user): void` | Link anonymous ID with login ID; both are required |

### Event Tracking

| Method | Description |
|--------|-------------|
| `trackEvent(User $user, string $eventName, array|Properties $properties = []): void` | Track a named event with properties |
| `track(Event $event): void` | Track a fully constructed event |

### User Profile Operations

| Method | Description |
|--------|-------------|
| `profileSet(User $user, array|Properties $properties): void` | Set user properties |
| `profileSetOnce(User $user, array|Properties $properties): void` | Set user properties only if they do not exist |
| `profileIncrement(User $user, array|Properties $properties): void` | Increment numeric user properties; non-numeric values are ignored |
| `profileAppend(User $user, array|ListProperties $properties): void` | Append values to list properties |
| `profileUnion(User $user, array|ListProperties $properties): void` | Append unique values to list properties |
| `profileUnset(User $user, string ...$propertyKeys): void` | Remove user properties |
| `profileDelete(User $user): void` | Delete the whole user profile |

### A/B Testing

| Method | Description |
|--------|-------------|
| `checkFeatureGate(User $user, string $key): bool` | Evaluate a feature gate |
| `getFeatureConfig(User $user, string $key): ABResult` | Evaluate a feature config |
| `getExperiment(User $user, string $key): ABResult` | Evaluate an experiment |
| `evaluateAll(User $user): array` | Evaluate all currently loaded specs and emit impressions |
| `getABSpecs(): string` | Export current A/B metadata snapshot |

### Request Signing

| Method | Description |
|--------|-------------|
| `RequestSigner::sign(...)` | Build ACS3-HMAC-SHA256 authorization headers |

## User Type

For all operations except `identify`, at least one of `anonId` or `loginId`
must be non-empty. For `identify`, both IDs are required.

```php
$user = new User(
    anonId: 'device-123',
    loginId: 'user-123',
    abUserProps: [
        '$app_version' => '12.4.0',
        '$country' => 'CN',
    ],
);
```

## Event Tracking

### Identify User

```php
$client->identify(new User(anonId: 'device-123', loginId: 'user-123'));
```

### Track Custom Event

```php
$client->trackEvent($user, 'CheckoutStarted', [
    'cart_value' => 199.0,
    'currency' => 'CNY',
]);
```

### Track with Full Event Structure

```php
use SensorsWave\Model\Event;
use SensorsWave\Model\Properties;

$event = Event::create('device-123', 'user-123', 'PurchaseCompleted')
    ->withProperties(
        Properties::create()
            ->set('order_id', 'O-1001')
            ->set('amount', 199.0)
    );

$client->track($event);
```

## User Profile Management

### Set Properties

```php
$client->profileSet($user, ['plan' => 'pro']);
```

### Set Once

```php
$client->profileSetOnce($user, ['first_plan' => 'starter']);
```

### Increment

```php
$client->profileIncrement($user, ['coins' => 3]);
```

### Append

```php
$client->profileAppend($user, ['tags' => ['php', 'sdk']]);
```

### Union

```php
$client->profileUnion($user, ['groups' => ['beta', 'internal', 'beta']]);
```

### Unset

```php
$client->profileUnset($user, 'legacy_field', 'stale_flag');
```

### Delete Profile

```php
$client->profileDelete($user);
```

## A/B Testing

### Feature Gate

```php
$passed = $client->checkFeatureGate($user, 'checkout_enabled');
```

### Feature Config

```php
$result = $client->getFeatureConfig($user, 'checkout_theme');
$theme = $result->getString('color', 'blue');
```

### Experiment

```php
$result = $client->getExperiment($user, 'pricing_experiment');
$variant = $result->variantId;
```

### Read Variant Payloads

```php
$payload = $result->getMap('layout', []);
$json = $result->jsonPayload();
```

## Configuration Options

### Client Config

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `trackUriPath` | string | `/in/track` | Event tracking path |
| `flushIntervalMs` | int | `10000` | Opportunistic flush interval |
| `httpConcurrency` | int | `1` | Max concurrent HTTP requests |
| `httpTimeoutMs` | int | `3000` | Per-request timeout |
| `httpRetry` | int | `2` | Retry attempts |
| `onTrackFailHandler` | callable | `null` | Tracking failure callback |
| `ab` | `?ABConfig` | `null` | A/B testing configuration |
| `transport` | `?TransportInterface` | `null` | Custom transport implementation |
| `logger` | `?LoggerInterface` | default logger | Custom logger |

### ABConfig

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `projectSecret` | string | — | Project secret for signed metadata requests |
| `metaEndpoint` | string | main endpoint | Metadata endpoint override |
| `metaUriPath` | string | `/ab/all4eval` | Metadata request path |
| `metaLoadIntervalMs` | int | `60000` | Refresh interval; minimum `30000` |
| `stickyHandler` | `?StickyHandlerInterface` | `null` | Sticky assignment storage |
| `metaLoader` | mixed | `null` | Custom metadata loader |
| `loadABSpecs` | string | `''` | Preloaded metadata snapshot |

## Advanced: Caching A/B Specs

```php
$snapshot = $client->getABSpecs();
file_put_contents(__DIR__ . '/ab-specs.json', $snapshot);

$client = Client::create(
    'https://collector.example.com',
    'your-source-token',
    new Config(
        ab: new ABConfig(
            loadABSpecs: file_get_contents(__DIR__ . '/ab-specs.json') ?: '',
        ),
    ),
);
```

## Predefined Properties

| Constant | Value | Description |
|----------|-------|-------------|
| `$lib` | `php` | SDK language identifier |
| `$lib_version` | `0.1.3` | SDK version |
| `$user_set_type` | varies | User profile operation type |
| `$feature_key` | varies | Feature key in impression payload |
| `$feature_variant` | varies | Feature variant in impression payload |
| `$exp_key` | varies | Experiment key in impression payload |
| `$exp_variant` | varies | Experiment variant in impression payload |

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
