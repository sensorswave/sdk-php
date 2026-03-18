# PHP SDK design

Date: March 19, 2026
Author: AI

## Overview

This document defines the design for a standalone PHP SDK repository at
`/Users/yanming/sol/sdk-php`. The goal is to deliver feature parity with the
current Go SDK while exposing a more idiomatic PHP API. The implementation must
preserve the Go SDK's behavioral contract for tracking, signature generation,
A/B metadata loading, rule evaluation, sticky assignment, and exposure logging.

The design optimizes for verifiable parity. Instead of writing a loosely
similar PHP client, it uses the Go SDK as the executable specification. The
tests, fixtures, and data flow from the Go repository define the expected
behavior for the PHP implementation.

## Goals

- Build a standalone PHP repository under `/Users/yanming/sol/sdk-php`.
- Preserve behavioral parity with the Go SDK across public features.
- Expose a PHP-style API without changing the underlying semantics.
- Port the existing Go test cases to PHPUnit with fixture reuse.
- Keep A/B evaluation and request signing algorithmically equivalent to Go.

## Non-goals

- Do not introduce new product features beyond the Go SDK.
- Do not redesign the A/B model or request protocol.
- Do not weaken parity requirements to make the PHP port smaller.

## Repository structure

The PHP repository uses a Composer package layout.

- `composer.json`
  Defines package metadata, runtime dependencies, dev dependencies, scripts, and
  PSR-4 autoloading.
- `src/Client/`
  Contains the public client entry point and lifecycle management.
- `src/Tracking/`
  Contains event objects, profile operations, batching, HTTP sending, and
  request payload serialization.
- `src/ABTesting/`
  Contains metadata loading, in-memory storage, rule evaluation, sticky
  handling, exposure logging, and `ABResult`.
- `src/Signing/`
  Contains the ACS3-HMAC-SHA256 signing implementation.
- `src/Contract/`
  Contains logger, sticky handler, and meta loader interfaces.
- `src/Support/`
  Contains tightly scoped shared helpers, such as UUID generation, time
  utilities, hashing, and version comparison.
- `tests/`
  Contains PHPUnit test suites mapped from the Go SDK test files.
- `tests/Fixtures/`
  Contains copied or synchronized fixtures from the Go repository `testdata/`
  and any additional cross-language expected results.

## Public API

The PHP SDK exposes a PHP-native surface area while keeping the Go SDK's
behavioral contract unchanged.

- `Client::create(string $endpoint, string $sourceToken, ?Config $config = null)`
- `Client::identify(User $user): void`
- `Client::trackEvent(User $user, string $event, array|Properties $properties = [])`
- `Client::track(Event $event): void`
- `Client::profileSet(User $user, array|Properties $properties): void`
- `Client::profileSetOnce(User $user, array|Properties $properties): void`
- `Client::profileIncrement(User $user, array|Properties $properties): void`
- `Client::profileAppend(User $user, array|ListProperties $properties): void`
- `Client::profileUnion(User $user, array|ListProperties $properties): void`
- `Client::profileUnset(User $user, string ...$propertyKeys): void`
- `Client::profileDelete(User $user): void`
- `Client::checkFeatureGate(User $user, string $key): bool`
- `Client::getFeatureConfig(User $user, string $key): ABResult`
- `Client::getExperiment(User $user, string $key): ABResult`
- `Client::getABSpecs(): string`
- `Client::close(): void`

Object models mirror the Go SDK semantics:

- `User`
  Stores `anonId`, `loginId`, and `abUserProperties`.
- `Event`
  Stores `anonId`, `loginId`, `time`, `traceId`, `event`, `properties`, and
  `userProperties`.
- `ABResult`
  Stores `id`, `key`, `type`, `variantId`, `variantParamValue`, and
  `disableImpress`, plus typed accessors.

## Behavioral parity rules

The PHP implementation must preserve the following rules taken from the Go SDK:

- `identify()` requires both `anonId` and `loginId`.
- All other user-bound methods require at least one ID.
- If both IDs are present, `loginId` takes precedence for identification.
- Default event properties, field names, and payload structure must remain
  compatible with the Go SDK.
- Missing A/B keys must produce the same empty or false semantics as Go.
- Exposure logging must follow the same triggers and suppression rules.
- Sticky assignment lookup and persistence timing must match Go behavior.

## A/B engine design

The A/B subsystem is split into deterministic layers to make parity auditable.

- `ABCore`
  Owns metadata loading, snapshot storage, and public evaluation entry points.
- `MetaLoader`
  Loads metadata from HTTP with the same signature protocol used by Go. A custom
  loader interface remains injectable.
- `Storage`
  Stores `updateTime`, `abEnv`, and keyed specs, matching the Go storage model.
- `Evaluator`
  Resolves overrides, traffic rollout, gates, experiments, holdouts, layers, and
  sticky behavior in the same order as the Go implementation.
- `ConditionMatcher`
  Evaluates string, number, boolean, null, time, version, array, and gate-based
  conditions.
- `ABResult`
  Provides typed accessors equivalent to the Go methods, such as `getString()`,
  `getNumber()`, `getBool()`, `getSlice()`, and `getMap()`.

The design keeps algorithmic boundaries close to Go source files so that test
case migration stays direct instead of interpretive.

## Request signing

The signing subsystem reproduces the Go SDK's `ACS3-HMAC-SHA256` flow.

- Normalize header names to lowercase for signing.
- Compute `x-content-sha256` from the request body.
- Generate `x-auth-timestamp` and `x-auth-nonce` if absent.
- Build the canonical request using the same method, URI, query string, and
  sorted headers rules.
- Hash the canonical request and sign it with HMAC-SHA256 using the project
  secret.
- Emit the `Authorization` header with the same credential, signed headers, and
  signature format as Go.

The PHP test suite must verify signed requests against the same cases covered by
the Go SDK.

## Testing strategy

The test strategy is strict because parity is the main product requirement.

- Map each Go test file to a PHP test file with the same scenario grouping.
- Reuse JSON fixtures and A/B metadata payloads from the Go repository wherever
  possible.
- Preserve assertion intent. Only adapt for host language syntax differences.
- Add cross-language fixture checks for request signing and serialized payloads
  when direct fixture reuse is insufficient.
- Use PHPUnit as the main test runner.
- Use static analysis to catch type and API drift in the PHP codebase.

## Migration policy for Go test cases

Each Go test theme must have a corresponding PHP suite:

- `track_test.go`
- `signature_test.go`
- `ab_gate_test.go`
- `ab_config_test.go`
- `ab_exp_test.go`
- `ab_meta_test.go`
- `ab_impress_test.go`

The PHP repository must not declare success until every relevant Go case is
either ported directly or represented by an equivalent PHPUnit case that targets
the same behavior and fixture inputs.

## Risks and controls

The main risks and mitigations are below.

- Risk: silent drift in A/B condition semantics.
  Control: port Go fixtures and keep evaluator steps aligned with Go symbols.
- Risk: request signing mismatch due to header normalization or canonicalization.
  Control: create direct parity tests based on the Go signature fixtures.
- Risk: PHP-friendly API hides behavior changes.
  Control: keep domain objects semantically aligned with Go and test behavior,
  not presentation.
- Risk: partial test migration creates false confidence.
  Control: track file-by-file case migration and block completion until all core
  suites are green.

## Validation

The implementation is complete only when all of the following are true:

- The PHP repository builds and autoloads via Composer.
- PHPUnit passes for all migrated suites.
- Static analysis passes.
- The PHP SDK can serialize tracking payloads and sign metadata requests in a
  way that matches Go expectations.
- The PHP A/B engine returns the same decisions for the migrated fixtures.
