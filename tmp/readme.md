# Fund Transfer API — Complete Technical Reference

## Table of Contents

1. [Overview](#1-overview)
2. [End-to-End Flow](#2-end-to-end-flow)
3. [Directory Structure](#3-directory-structure)
4. [List of Services](#4-list-of-services)
5. [Entities and Relations](#5-entities-and-relations)
6. [How Doctrine Is Used](#6-how-doctrine-is-used)
7. [Authentication and Security](#7-authentication-and-security)
8. [Async Messaging (Symfony Messenger)](#8-async-messaging-symfony-messenger)
9. [Logging and Observability](#9-logging-and-observability)
10. [Test Cases](#10-test-cases)
11. [Bugs Found](#11-bugs-found)
12. [Weak Implementations](#12-weak-implementations)
13. [Missing Implementations](#13-missing-implementations)
14. [Infrastructure Notes](#14-infrastructure-notes)

---

## 1. Overview

This repository is a **REST API for secure fund transfers between accounts**, built with:

| Component   | Technology             |
|-------------|------------------------|
| Framework   | Symfony 7.3            |
| Language    | PHP 8.3                |
| Database    | MySQL 8.3 (InnoDB)     |
| Cache/Lock  | Redis 7                |
| Auth        | JWT (LexikJWTBundle)   |
| Messaging   | Symfony Messenger      |
| Precision   | bcmath (never floats)  |

### What It Does

A single API endpoint `POST /api/v1/transfers` accepts a JSON payload with source account, destination account, amount, currency, and idempotency key. The system:

1. Authenticates the caller via JWT
2. Validates all input fields
3. Ensures the request hasn't already been processed (idempotency)
4. Acquires a distributed Redis lock on the account pair
5. Opens a MySQL transaction with pessimistic row locks
6. Validates business rules (account active, currency match, sufficient funds)
7. Debits the source and credits the destination using exact decimal arithmetic
8. Records a Transaction entity
9. Writes an audit log
10. Dispatches an async message for post-commit side-effects

### Request / Response Example

**Request:**
```json
POST /api/v1/transfers
Authorization: Bearer <jwt>
Content-Type: application/json

{
  "sourceAccountId":      "11111111-1111-4111-8111-111111111111",
  "destinationAccountId": "22222222-2222-4222-8222-222222222222",
  "amount":               "100.00",
  "currency":             "USD",
  "idempotencyKey":       "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response (201 Created):**
```json
{
  "transaction_id": "019daef6-c031-7e1e-b0ea-404a273d8ba6",
  "status":         "completed",
  "amount":         "100.00",
  "currency":       "USD",
  "source_account": "11111111-1111-4111-8111-111111111111",
  "dest_account":   "22222222-2222-4222-8222-222222222222",
  "completed_at":   "2026-04-21T10:00:00+00:00"
}
```

---

## 2. End-to-End Flow

```
Client (curl / frontend)
   │
   │  POST /api/v1/transfers  +  Bearer JWT
   ▼
┌──────────────────────────────────────────────────────────────────┐
│  LAYER 1 — Symfony Security Firewall                            │
│                                                                  │
│  Config: config/packages/security.yaml                           │
│  - Firewall "api" matches ^/api/ routes                          │
│  - Stateless = true (no session)                                 │
│  - JWT provider via LexikJWTAuthenticationBundle                 │
│  - access_control requires IS_AUTHENTICATED_FULLY                │
│                                                                  │
│  Result: 401 if token is missing, expired, or invalid            │
│          Otherwise, sets authenticated UserInterface on request   │
└──────────────────────┬───────────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────────┐
│  LAYER 2 — TransferController::create()                         │
│  File: src/Controller/Api/V1/TransferController.php              │
│                                                                  │
│  Step A: Deserialize JSON body into TransferRequest DTO          │
│    - Uses Symfony Serializer                                     │
│    - Catches malformed JSON → 400 INVALID_JSON                   │
│                                                                  │
│  Step B: Validate DTO via Symfony Validator                      │
│    - NotBlank, Uuid, Positive, Regex, Currency constraints       │
│    - Failures → 422 VALIDATION_ERROR with field-level errors     │
│                                                                  │
│  Step C: Call TransferService::execute(dto, user, clientIp)      │
│                                                                  │
│  Step D: Map domain exceptions to HTTP responses:                │
│    - DuplicateTransferException  → 409 DUPLICATE_REQUEST         │
│    - AccountNotFoundException    → 404 ACCOUNT_NOT_FOUND         │
│    - AccountNotActiveException   → 403 ACCOUNT_NOT_ACTIVE        │
│    - InsufficientFundsException  → 422 INSUFFICIENT_FUNDS        │
│    - CurrencyMismatchException   → 422 CURRENCY_MISMATCH         │
│    - SelfTransferException       → 422 SELF_TRANSFER             │
│    - RuntimeException            → 503 SERVICE_UNAVAILABLE       │
│    - Throwable (catch-all)       → 500 INTERNAL_ERROR            │
└──────────────────────┬───────────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────────┐
│  LAYER 3 — TransferService::execute()                           │
│  File: src/Service/TransferService.php                           │
│                                                                  │
│  ┌─ Guard ─────────────────────────────────────────────────┐     │
│  │ If sourceAccountId == destinationAccountId              │     │
│  │   → throw SelfTransferException                         │     │
│  └─────────────────────────────────────────────────────────┘     │
│                                                                  │
│  ┌─ Step 1: Idempotency Check (Redis) ────────────────────┐     │
│  │ IdempotencyService::check(key)                          │     │
│  │   returns false  → key is new, proceed                  │     │
│  │   returns null   → in_progress by another worker        │     │
│  │                    → throw DuplicateTransferException    │     │
│  │   returns array  → already completed                    │     │
│  │                    → return cached response (replay)     │     │
│  │                                                          │     │
│  │ IdempotencyService::markInProgress(key)                  │     │
│  │   SET NX with 30s TTL; if fails → another worker won    │     │
│  │                    → throw DuplicateTransferException    │     │
│  └──────────────────────────────────────────────────────────┘     │
│                                                                  │
│  ┌─ Step 2: Distributed Lock (Redis) ─────────────────────┐     │
│  │ Lock key = sorted(srcId, destId) → prevents AB/BA       │     │
│  │   deadlock between concurrent requests                   │     │
│  │ DistributedLockService::acquire(key)                     │     │
│  │   SET NX PX 10000 (10s TTL) with random token            │     │
│  │   Fails → markFailed idempotency key, throw 503          │     │
│  └──────────────────────────────────────────────────────────┘     │
│                                                                  │
│  ┌─ Step 3-8: runInTransaction() [inside DB transaction] ─┐     │
│  │                                                          │     │
│  │ 3. Load both accounts with PESSIMISTIC WRITE lock        │     │
│  │    (SELECT ... FOR UPDATE, sorted by ID)                 │     │
│  │    AccountRepository::findMultipleWithPessimisticLock()  │     │
│  │    Missing account → throw AccountNotFoundException      │     │
│  │                                                          │     │
│  │ 4. Business rule validation:                             │     │
│  │    - Source must be Active → else AccountNotActive        │     │
│  │    - Dest must be Active   → else AccountNotActive        │     │
│  │    - Currency must match across all three                 │     │
│  │                      → else CurrencyMismatchException    │     │
│  │                                                          │     │
│  │ 5. Debit source / Credit destination:                    │     │
│  │    - Account::debit()  → bcsub, throws                   │     │
│  │      InsufficientFundsException if result < 0            │     │
│  │    - Account::credit() → bcadd                           │     │
│  │                                                          │     │
│  │ 6. Create and persist Transaction entity (UUID v7)       │     │
│  │                                                          │     │
│  │ 7. Write structured audit log (Monolog "audit" channel)  │     │
│  │                                                          │     │
│  │ 8. Dispatch TransferCompletedMessage to Messenger bus    │     │
│  └──────────────────────────────────────────────────────────┘     │
│                                                                  │
│  On success: IdempotencyService::markComplete(key, response)     │
│  On failure: IdempotencyService::markFailed(key) [deletes key]   │
│  Finally:    DistributedLockService::release(key, token)         │
└──────────────────────┬───────────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────────┐
│  LAYER 4 — Async Worker (Symfony Messenger)                     │
│  File: src/MessageHandler/TransferCompletedHandler.php           │
│                                                                  │
│  Consumes TransferCompletedMessage from Redis stream             │
│  - Loads Transaction from DB                                     │
│  - Logs processing info                                          │
│  - (Placeholder for: email, webhook, reconciliation)             │
│                                                                  │
│  Transport: Redis stream "transfer_messages"                     │
│  Retry: 3 retries, exponential backoff (1s, 2s, 4s)             │
│  Failed: Doctrine-backed "failed" transport                      │
└──────────────────────────────────────────────────────────────────┘
```

### Concurrency Safety Model (Two Layers)

| Layer | Mechanism | Purpose |
|-------|-----------|---------|
| Application | Redis distributed lock (SET NX PX + Lua CAS release) | Prevents multiple workers from even entering the DB transaction for the same account pair |
| Database | MySQL `SELECT ... FOR UPDATE` (pessimistic write lock) | Serialises row access within InnoDB; prevents double-spend even if Redis lock leaks |

Both layers sort by account ID before locking to guarantee consistent ordering and prevent AB/BA deadlocks.

---

## 3. Directory Structure

```
src/
├── Controller/
│   └── Api/V1/
│       └── TransferController.php      # REST endpoint, validation, exception mapping
├── DTO/
│   └── TransferRequest.php             # Immutable request DTO with Symfony constraints
├── Entity/
│   ├── Account.php                     # Account entity (balance, debit, credit)
│   ├── AccountStatus.php               # Enum: active, frozen, closed
│   ├── Transaction.php                 # Transaction record entity
│   └── TransactionStatus.php           # Enum: pending, completed, failed, reversed
├── EventListener/
│   └── ApiExceptionListener.php        # Global JSON error handler for /api/* routes
├── Exception/
│   ├── AccountNotFoundException.php     # 404
│   ├── AccountNotActiveException.php    # 403
│   ├── CurrencyMismatchException.php    # 422
│   ├── DuplicateTransferException.php   # 409
│   ├── InsufficientFundsException.php   # 422
│   └── SelfTransferException.php        # 422
├── Message/
│   └── TransferCompletedMessage.php    # Messenger message (transactionId)
├── MessageHandler/
│   └── TransferCompletedHandler.php    # Async handler (email, webhook — TODO)
├── Repository/
│   ├── AccountRepository.php           # Pessimistic lock queries
│   └── TransactionRepository.php       # Lookup by idempotency key, recent history
├── Service/
│   ├── DistributedLockService.php      # Redis SET NX PX + Lua release
│   ├── IdempotencyService.php          # Redis 3-state idempotency machine
│   └── TransferService.php             # Core business logic orchestrator
└── Kernel.php

tests/
├── Unit/Service/
│   └── TransferServiceTest.php         # 6 tests with mocked dependencies
├── Integration/
│   ├── ApiTestCase.php                 # Base class: DB setup, JWT gen, helpers
│   └── Controller/
│       └── TransferControllerTest.php  # 11 HTTP-level integration tests
└── Concurrent/
    └── ConcurrentTransferTest.php      # curl_multi double-spend test
```

---

## 4. List of Services

### 4.1 TransferService (`src/Service/TransferService.php`)

**Role:** Core orchestrator. Coordinates idempotency, locking, validation, balance mutation, persistence, and event dispatch.

| Dependency | Injected As | Purpose |
|------------|-------------|---------|
| `EntityManagerInterface` | `$em` | DB transactions, entity persistence |
| `AccountRepository` | `$accountRepo` | Pessimistic lock account queries |
| `IdempotencyService` | `$idempotency` | Redis idempotency state machine |
| `DistributedLockService` | `$lockService` | Redis distributed lock |
| `MessageBusInterface` | `$bus` | Async event dispatch |
| `LoggerInterface` | `$auditLogger` | Monolog "audit" channel |

**Public method:** `execute(TransferRequest $dto, UserInterface $user, ?string $ipAddress): array`

### 4.2 IdempotencyService (`src/Service/IdempotencyService.php`)

**Role:** Three-state Redis-backed idempotency machine.

| State | Redis Value | TTL | Meaning |
|-------|-------------|-----|---------|
| Not found | (no key) | — | New request, proceed |
| `in_progress` | `{"state":"in_progress"}` | 30 seconds | Another worker is processing |
| `complete` | `{"state":"complete","response":{...}}` | 24 hours | Already done, return cached |

**Methods:**
- `check(key)` → `false` (new) / `null` (in_progress) / `array` (completed response)
- `markInProgress(key)` → `bool` (SET NX)
- `markComplete(key, response)` → `void` (SETEX 24h)
- `markFailed(key)` → `void` (DEL — allows client retry)

### 4.3 DistributedLockService (`src/Service/DistributedLockService.php`)

**Role:** Redis-based mutual exclusion for account pairs.

**Methods:**
- `acquire(resource)` → `string|false` (returns opaque token or false)
- `release(resource, token)` → `void` (Lua compare-and-delete)
- `accountPairKey(idA, idB)` → `string` (static; sorts IDs for consistent ordering)

**Lock parameters:**
- Prefix: `lock:`
- TTL: 10,000 ms
- Token: 16 random bytes (hex-encoded)

### 4.4 ApiExceptionListener (`src/EventListener/ApiExceptionListener.php`)

**Role:** Global catch-all for unhandled exceptions on `/api/*` routes. Converts them to structured JSON. Shows stack trace only in `dev` environment.

### 4.5 TransferCompletedHandler (`src/MessageHandler/TransferCompletedHandler.php`)

**Role:** Async post-commit handler. Currently only logs; has TODOs for email, webhook, and reconciliation.

---

## 5. Entities and Relations

### 5.1 Account Entity (`src/Entity/Account.php`)

| Column | Type | Notes |
|--------|------|-------|
| `id` | `CHAR(36)` PK | UUID, not auto-increment |
| `owner_id` | `CHAR(36)` | Owner reference (indexed) |
| `currency` | `CHAR(3)` | ISO 4217 (USD, EUR, etc.) |
| `balance` | `DECIMAL(20,8)` | Stored as PHP string; bcmath only |
| `status` | `ENUM('active','frozen','closed')` | PHP enum `AccountStatus` |
| `version` | `INT` | Doctrine `@Version` for optimistic locking (unused) |
| `created_at` | `DATETIME(6)` | Microsecond precision |
| `updated_at` | `DATETIME(6)` | Auto-updated via `@PreUpdate` lifecycle callback |

**DB constraints:**
- `CHECK (balance >= 0)` — database-level overdraft prevention
- Index: `idx_owner(owner_id)`, `idx_currency_status(currency, status)`

**Business methods:**
- `debit(string $amount)` — subtracts via `bcsub`, throws `InsufficientFundsException` if result < 0
- `credit(string $amount)` — adds via `bcadd`, validates amount > 0
- `freeze()` — sets status to Frozen
- `isActive()` — returns true only for `AccountStatus::Active`

### 5.2 Transaction Entity (`src/Entity/Transaction.php`)

| Column | Type | Notes |
|--------|------|-------|
| `id` | `CHAR(36)` PK | UUID v7 (time-ordered) |
| `idempotency_key` | `CHAR(36)` UNIQUE | Client-provided dedup key |
| `source_account_id` | `CHAR(36)` | Plain string (no FK) |
| `dest_account_id` | `CHAR(36)` | Plain string (no FK) |
| `amount` | `DECIMAL(20,8)` | Transfer amount |
| `currency` | `CHAR(3)` | Currency used |
| `status` | `ENUM('pending','completed','failed','reversed')` | PHP enum `TransactionStatus` |
| `failure_reason` | `VARCHAR(512)` NULL | Filled when status=failed |
| `initiated_by` | `VARCHAR(255)` | JWT user identifier |
| `ip_address` | `VARCHAR(45)` NULL | Client IP (supports IPv6) |
| `created_at` | `DATETIME(6)` | When created |
| `completed_at` | `DATETIME(6)` NULL | When completed |

**DB constraints:**
- `UNIQUE(idempotency_key)` — permanent dedup safety net
- `CHECK (amount > 0)` — database-level positive amount enforcement
- Indexes: `idx_source`, `idx_dest`, `idx_status`, `idx_created`

### 5.3 Entity Relationship Diagram

```
┌─────────────────────┐          ┌──────────────────────────┐
│      accounts       │          │      transactions        │
├─────────────────────┤          ├──────────────────────────┤
│ id            PK    │◄── ─ ─ ─│ source_account_id        │
│ owner_id            │          │ dest_account_id          │
│ currency            │◄── ─ ─ ─│                          │
│ balance             │          │ id                  PK   │
│ status              │          │ idempotency_key    UQ    │
│ version             │          │ amount                   │
│ created_at          │          │ currency                 │
│ updated_at          │          │ status                   │
└─────────────────────┘          │ failure_reason           │
                                 │ initiated_by             │
     ─ ─ ─  = logical link       │ ip_address               │
     (no FK constraint exists)   │ created_at               │
                                 │ completed_at             │
                                 └──────────────────────────┘
```

**Important:** The dashed lines indicate logical relationships only. There are **no foreign key constraints** defined in the migration. `source_account_id` and `dest_account_id` are plain `CHAR(36)` columns with no `FOREIGN KEY` reference. The Transaction entity stores account IDs as strings, not as Doctrine `@ManyToOne` associations.

### 5.4 Enums

**AccountStatus** (`src/Entity/AccountStatus.php`):
- `Active` — can send and receive transfers
- `Frozen` — blocked from all transfers
- `Closed` — terminal state (no code uses this today)

**TransactionStatus** (`src/Entity/TransactionStatus.php`):
- `Pending` — defined but never set by current code
- `Completed` — the only status ever written
- `Failed` — `markFailed()` method exists, never called from service
- `Reversed` — defined but no reversal logic exists

---

## 6. How Doctrine Is Used

### 6.1 Entity Mapping

Entities use PHP 8 attributes (`#[ORM\Entity]`, `#[ORM\Column]`, etc.) rather than XML or YAML mapping. Mapping configuration in `config/packages/doctrine.yaml` uses `auto_mapping: true` with the `App\Entity` namespace prefix.

### 6.2 Migrations

A single migration `Version20240101000001.php` creates both tables using raw SQL (`addSql`). This is raw DDL, not Doctrine Schema Builder, which means:
- MySQL-specific syntax (`ENUM`, `ENGINE=InnoDB`)
- Explicit `CHECK` constraints
- Explicit `INDEX` definitions
- Not portable to other databases

### 6.3 Transaction Management

The service uses `EntityManagerInterface::wrapInTransaction()`:

```php
return $this->em->wrapInTransaction(function () use (...) {
    // all work here
    // flush is called automatically on commit
});
```

This ensures automatic rollback on any exception and automatic flush on success.

### 6.4 Pessimistic Locking

`AccountRepository::findMultipleWithPessimisticLock()` uses Doctrine's `LockMode::PESSIMISTIC_WRITE`:

```php
->getQuery()
->setLockMode(LockMode::PESSIMISTIC_WRITE)
->getResult();
```

This translates to `SELECT ... FOR UPDATE` in MySQL. IDs are sorted before querying to ensure consistent lock acquisition order across concurrent requests.

### 6.5 Optimistic Locking (Defined but Unused)

The `Account` entity has a `#[ORM\Version]` column. Doctrine auto-increments this on each UPDATE and would throw `OptimisticLockException` on version conflicts. However, no code actually checks or leverages this — the pessimistic lock strategy makes it redundant.

### 6.6 Repository Pattern

Both repositories extend `ServiceEntityRepository<T>`:
- `AccountRepository` — `findWithPessimisticLock(id)`, `findMultipleWithPessimisticLock(ids[])`
- `TransactionRepository` — `findByIdempotencyKey(key)`, `findRecentForAccount(id, limit)`

### 6.7 Lifecycle Callbacks

`Account` uses `#[ORM\HasLifecycleCallbacks]` with a `#[ORM\PreUpdate]` hook that sets `updatedAt` to the current time. Note: `debit()` and `credit()` also manually set `updatedAt`, so the lifecycle callback is redundant for transfer operations.

### 6.8 Strict SQL Mode

Doctrine DBAL is configured to force strict mode on every connection:
```yaml
options:
    1002: "SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
```

This prevents silent data truncation and ensures errors on division by zero.

### 6.9 Test Environment

`when@test` appends `_test` to the database name so integration tests run against a separate database.

### 6.10 Production Caching

`when@prod` disables proxy auto-generation and enables query/result cache pools.

---

## 7. Authentication and Security

### 7.1 JWT Authentication

- **Bundle:** `lexik/jwt-authentication-bundle`
- **Config:** `config/packages/lexik_jwt_authentication.yaml`
- **Key pair:** RSA (private.pem / public.pem) in `config/jwt/`
- **TTL:** Configurable via `JWT_TTL` env var (default 3600s in docker-compose)
- **Token extraction:** `Authorization: Bearer <token>` header

### 7.2 Security Firewall

```yaml
firewalls:
    api:
        pattern: ^/api/
        stateless: true
        provider: jwt
        jwt: ~

access_control:
    - { path: ^/api/, roles: IS_AUTHENTICATED_FULLY }
```

All `/api/*` routes require a valid JWT. No sessions are created.

### 7.3 Rate Limiting (Configured But Not Enforced)

`config/packages/rate_limiter.yaml` defines a `transfer_api` limiter with 60 requests/minute sliding window, but **no code in the controller calls it**.

---

## 8. Async Messaging (Symfony Messenger)

### 8.1 Transport Configuration

```yaml
transports:
    async:
        dsn: redis://redis:6379/messages
        options:
            stream: transfer_messages
            group: transfer_workers
        retry_strategy:
            max_retries: 3
            delay: 1000
            multiplier: 2
    failed:
        dsn: doctrine://default?queue_name=failed
```

### 8.2 Message Routing

`App\Message\TransferCompletedMessage` → `async` transport (Redis stream)

### 8.3 Worker

Docker Compose runs a dedicated worker container:
```bash
php bin/console messenger:consume async --limit=500 --time-limit=3600 -vv
```

### 8.4 Handler

`TransferCompletedHandler` currently:
- Loads the Transaction from DB
- Logs start/end
- Has TODO placeholders for email, webhook, and reconciliation

---

## 9. Logging and Observability

### 9.1 Monolog Channels

| Channel | Handler | Output | Purpose |
|---------|---------|--------|---------|
| `main` | stream | `var/log/{env}.log` | General app logs (excludes audit) |
| `audit` | stream | `var/log/audit.log` | Transfer audit trail |
| `console` | console | stdout | CLI command output |

### 9.2 Audit Log Fields

Every completed transfer logs to the `audit` channel:
```
transaction_id, source_account, dest_account, amount, currency,
initiated_by, idempotency_key, ip_address
```

### 9.3 Production Logging

In `prod`, the main handler uses `fingers_crossed` strategy (only logs on error), and audit logs go to `php://stderr` for container log aggregation.

---

## 10. Test Cases

### 10.1 Unit Tests (`tests/Unit/Service/TransferServiceTest.php`)

6 tests covering `TransferService` with fully mocked dependencies:

| # | Test Method | What It Verifies |
|---|-------------|-----------------|
| 1 | `testSuccessfulTransfer` | Happy path: balances updated, Transaction persisted, message dispatched, response contains all fields |
| 2 | `testInsufficientFundsThrows` | Amount > balance throws `InsufficientFundsException` |
| 3 | `testSelfTransferThrows` | sourceId == destId throws `SelfTransferException` |
| 4 | `testIdempotentReplayReturnsOriginalResponse` | When `check()` returns cached array, returns it without DB work |
| 5 | `testCurrencyMismatchThrows` | DTO currency != account currency throws `CurrencyMismatchException` |
| 6 | `testSourceAccountNotFoundThrows` | When source account is missing from DB results, throws `AccountNotFoundException` |

**Mocked:** EntityManager, AccountRepository, IdempotencyService, DistributedLockService, MessageBus. Logger uses `NullLogger`.

### 10.2 Integration Tests (`tests/Integration/Controller/TransferControllerTest.php`)

11 tests making real HTTP requests through the Symfony kernel with a real MySQL database:

| # | Test Method | HTTP Status | Verified Behavior |
|---|-------------|-------------|-------------------|
| 1 | `testSuccessfulTransferReturns201` | 201 | Full transfer with balance verification |
| 2 | `testIdempotentReplayReturnsSameResponse` | 201 | Same idempotency key returns same transaction_id, no double debit |
| 3 | `testInsufficientFundsReturns422` | 422 | Balance unchanged on rejection |
| 4 | `testSelfTransferReturns422` | 422 | Same source and dest rejected |
| 5 | `testCurrencyMismatchReturns422` | 422 | USD account + EUR transfer rejected |
| 6 | `testFrozenAccountReturns403` | 403 | Frozen account cannot send |
| 7 | `testNonExistentAccountReturns404` | 404 | Unknown UUID rejected |
| 8 | `testMissingFieldsReturns422` | 422 | Partial payload fails validation |
| 9 | `testInvalidJsonReturns400` | 400 | Malformed JSON handled gracefully |
| 10 | `testMissingTokenReturns401` | 401 | No JWT = unauthorized |
| 11 | `testSmallDecimalAmountHandledExactly` | 201 | 0.00000001 transfer preserves precision to 8 decimal places |

**Test infrastructure (`ApiTestCase.php`):**
- Cleans DB before each test (`DELETE FROM transactions; DELETE FROM accounts`)
- Seeds 4 accounts: source (1000 USD), dest (500 USD), EUR (200 EUR), frozen (0 USD)
- Generates JWT via `lexik_jwt_authentication.jwt_manager`
- Helper methods: `post()`, `getResponseData()`, `getStatusCode()`, `getAccountBalance()`, `countTransactions()`

### 10.3 Concurrent Tests (`tests/Concurrent/ConcurrentTransferTest.php`)

1 test using `curl_multi` to simulate 20 parallel transfers:

| # | Test Method | What It Verifies |
|---|-------------|-----------------|
| 1 | `testNoConcurrentDoubleSpend` | All 20 requests fire simultaneously; asserts at least 1 succeeds, all transaction IDs are unique (no double-spend), and success + failure counts sum to 20 |

**Requires:** Running app server, env vars `TEST_JWT_TOKEN`, `TEST_SOURCE_ACCOUNT_ID`, `TEST_DEST_ACCOUNT_ID`. Excluded from default PHPUnit runs via `@group concurrent`.

### 10.4 Test Configuration (`phpunit.xml.dist`)

- Three test suites: `unit`, `integration`, `concurrent`
- Concurrent group excluded by default
- Symfony PHPUnit bridge extension registered (note: duplicated — see bugs)

---

## 11. Bugs Found

### BUG-1: Redis lock release passes wrong arguments (Critical)

**File:** `src/Service/DistributedLockService.php:42`

```php
$this->redis->eval($script, [$token], 1);
```

The `eval()` signature is `eval(script, keysAndArgs[], numKeys)`. This passes `$token` as `KEYS[1]`, but the Lua script expects `KEYS[1]` to be the Redis key and `ARGV[1]` to be the token. The actual Redis key (`lock:account-pair:...`) is never passed.

**Correct call:**
```php
$this->redis->eval($script, [self::PREFIX . $resource, $token], 1);
```

**Impact:** Lock release silently fails. Every lock is held for the full 10s TTL, meaning consecutive transfers to the same account pair will get 503 errors for up to 10 seconds.

### BUG-2: Messenger dispatch is inside the DB transaction (Medium)

**File:** `src/Service/TransferService.php:177`

`$this->bus->dispatch(...)` runs inside `wrapInTransaction()`. If the Redis messenger transport is unreachable, the exception bubbles up and **rolls back the entire DB transaction**, losing a valid transfer.

**Fix:** Use `DispatchAfterCurrentBusStamp`:
```php
$this->bus->dispatch(
    new Envelope(new TransferCompletedMessage(...)),
    [new DispatchAfterCurrentBusStamp()]
);
```

### BUG-3: Duplicate PHPUnit extension registration

**File:** `phpunit.xml.dist:68-75`

`SymfonyExtension` bootstrap is registered twice in the `<extensions>` block.

### BUG-4: Conflicting APP_ENV in phpunit.xml.dist

**File:** `phpunit.xml.dist:11,17`

Line 11: `<server name="APP_ENV" value="test" force="true"/>` (correct)
Line 17: `<env name="APP_ENV" value="dev"/>` (conflicting)

The `<server>` with `force="true"` wins, but the conflicting `<env>` is confusing.

### BUG-5: Unused Postgres service in docker-compose.yml

**File:** `docker-compose.yml:92-109`

A leftover `database` service for PostgreSQL was auto-generated by the Doctrine bundle recipe. The app uses the `db` MySQL service. The Postgres service is dead config and wastes resources if `docker compose up` starts it.

---

## 12. Weak Implementations

### WEAK-1: Rate limiter is configured but never enforced

`config/packages/rate_limiter.yaml` defines `transfer_api` (60/min sliding window), but the controller never injects `RateLimiterFactory` and never calls `$limiter->consume()`. Rate limiting is **completely non-functional**.

### WEAK-2: Redis host is hardcoded to 127.0.0.1

`config/services.yaml:18` hardcodes the Redis connection:
```yaml
Redis:
    class: \Redis
    calls:
        - connect: ['127.0.0.1', 6379]
```

This ignores the `REDIS_DSN` env var. Inside Docker, Redis runs at host `redis`, not `127.0.0.1`. The app **cannot connect to Redis inside Docker**.

### WEAK-3: Distributed lock has no retry mechanism

`DistributedLockService::acquire()` tries exactly once. If the lock is held, it immediately returns `false` and the caller gets a 503. Production systems typically retry 2-3 times with small delays (e.g., 50-100ms) before giving up.

### WEAK-4: No lock renewal or fencing token

The Redis lock TTL is 10 seconds. If a DB transaction takes longer (GC pause, slow query, replication lag), the lock expires while the transaction is still running. Another worker can then acquire the lock. The pessimistic DB lock is the safety net, but the Redis lock becomes meaningless.

### WEAK-5: No foreign key constraints

`Transaction.source_account_id` and `dest_account_id` are plain strings with no `FOREIGN KEY` reference. The entity doesn't use Doctrine `@ManyToOne` relationships. Consequences:
- No referential integrity at DB level
- Orphaned transactions can exist
- No cascade delete/update
- Cannot use `$transaction->getSourceAccount()` without manual query

### WEAK-6: Idempotency key TTL is only 24 hours

After 24 hours, Redis forgets the completed key. Replaying the same idempotency key would process the transfer again. The `UNIQUE(idempotency_key)` DB constraint would catch it, but the resulting DB exception would surface as a 500 error, not a clean 409.

### WEAK-7: Account::debit() does not validate positive amount

`credit()` rejects non-positive amounts, but `debit()` does not. Passing a negative amount to `debit()` would call `bcsub(balance, negative)` which **increases** the balance.

### WEAK-8: PHP built-in server in production Dockerfile

```dockerfile
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/"]
```

The PHP built-in web server is single-threaded, not production-ready. All requests are serialized. Should use nginx + php-fpm, FrankenPHP, or RoadRunner.

### WEAK-9: No account seeder or fixtures

The migration creates empty tables. There is no command, fixture, or data seeder to create accounts. The README does not explain how to insert accounts before making API calls.

### WEAK-10: Transaction uses only "Completed" status

`TransactionStatus` defines `Pending`, `Completed`, `Failed`, and `Reversed`, but only `Completed` is ever written. `markFailed()` exists on the entity but is never called from the service. No code handles the `Reversed` status.

---

## 13. Missing Implementations

### HIGH Priority

| # | Feature | Notes |
|---|---------|-------|
| 1 | **GET /api/v1/transfers/{id}** | No way to check a transfer status after creation |
| 2 | **GET /api/v1/accounts/{id}/balance** | No way to check balance via API |
| 3 | **Account management endpoints** | No CRUD for accounts (create, freeze, close, list) |
| 4 | **Rate limiter enforcement** | Config exists, code does not use it |
| 5 | **Redis host from env var** | Hardcoded to localhost; broken in Docker |

### MEDIUM Priority

| # | Feature | Notes |
|---|---------|-------|
| 6 | **Replay attack prevention** | Listed in original requirements; not implemented beyond idempotency |
| 7 | **Email/webhook notifications** | Handler has TODOs, no actual implementation |
| 8 | **Reconciliation / balance checks** | Handler has TODO, no implementation |
| 9 | **Transaction reversal** | `TransactionStatus::Reversed` exists; no reversal endpoint or logic |
| 10 | **GET /api/v1/accounts/{id}/transactions** | Repository method `findRecentForAccount()` exists but no controller uses it |
| 11 | **Proper post-commit message dispatch** | Use `DispatchAfterCurrentBusStamp` to avoid rollback on transport failure |

### LOW Priority

| # | Feature | Notes |
|---|---------|-------|
| 12 | **Health check endpoint** | No `/health` or `/ready` for container orchestration |
| 13 | **Metrics and tracing** | Required by original prompt; no Prometheus, no OpenTelemetry |
| 14 | **Foreign keys in migration** | No FK between transactions and accounts |
| 15 | **Account close/reactivate** | `Closed` status exists, no `close()` or `reactivate()` method |
| 16 | **Pagination** | No pagination on any query result |
| 17 | **CORS** | No CORS headers for browser-based clients |
| 18 | **API versioning strategy** | URL prefix `/api/v1/` exists but no mechanism for v2 |
| 19 | **Data fixtures / seeders** | No way to populate initial accounts |
| 20 | **Minimum transfer amount** | No configurable minimum; allows 0.00000001 transfers |

---

## 14. Infrastructure Notes

### Docker Compose Services

| Service | Image | Purpose | Port |
|---------|-------|---------|------|
| `app` | Custom (Dockerfile) | PHP built-in server | 8080 |
| `worker` | Same Dockerfile | Messenger consumer | — |
| `db` | mysql:8.3 | Primary database | 3306 |
| `redis` | redis:7-alpine | Locks, idempotency, messenger transport | 6379 |
| `database` | postgres:16-alpine | **UNUSED** (leftover from Doctrine recipe) | — |

### MySQL Tuning (docker-compose.yml)

```
--innodb-flush-log-at-trx-commit=1   # flush every commit (ACID)
--sync-binlog=1                       # sync binlog every commit
--sql-mode=STRICT_TRANS_TABLES,...    # no silent truncation
```

### Redis Configuration

```
--maxmemory 256mb
--maxmemory-policy allkeys-lru        # evict least-recently-used when full
--appendonly yes                       # AOF persistence
```

**Warning:** `allkeys-lru` eviction policy means Redis may evict idempotency keys or lock keys under memory pressure. For a financial system, `noeviction` (return errors when full) or `volatile-lru` (only evict keys with TTL) would be safer.
