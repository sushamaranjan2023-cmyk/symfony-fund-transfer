# Fund Transfer API

REST API for secure fund transfers between accounts, built with Symfony 7, MySQL, and Redis.

## Architecture

| Layer | Technology | Purpose |
|---|---|---|
| HTTP | Symfony 7 + JWT | Auth, routing, input validation |
| Business logic | TransferService | Debit/credit, business rules |
| Idempotency | Redis SET NX | Prevent duplicate processing |
| Distributed lock | Redis Lua CAS | Serialise concurrent transfers |
| Data integrity | MySQL InnoDB + SELECT FOR UPDATE | ACID compliance, pessimistic locking |
| Async | Symfony Messenger | Post-commit notifications |
| Audit | Monolog JSON | Structured audit trail |

## Requirements

| Tool | Version |
|---|---|
| PHP | 8.3+ |
| MySQL | 8.0+ |
| Redis | 6+ (Memurai on Windows) |
| Composer | 2.x |
| ext-bcmath | any |
| ext-redis | any |
| ext-sodium | any |
| ext-intl | any |

```

## setup (Docker)

```bash
docker compose up -d
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console lexik:jwt:generate-keypair
```

## Seed test accounts

```sql
INSERT INTO accounts (id, owner_id, currency, balance, status, version, created_at, updated_at) VALUES
('11111111-1111-4111-8111-111111111111', 'owner-1', 'USD', '1000.00000000', 'active', 0, NOW(6), NOW(6)),
('22222222-2222-4222-8222-222222222222', 'owner-2', 'USD', '0.00000000',    'active', 0, NOW(6), NOW(6));
```

## API reference

### POST /api/v1/transfers

**Headers**
```
Authorization: Bearer <jwt>
Content-Type: application/json
```

**Request body**
```json
{
  "sourceAccountId":      "11111111-1111-4111-8111-111111111111",
  "destinationAccountId": "22222222-2222-4222-8222-222222222222",
  "amount":               "100.00",
  "currency":             "USD",
  "idempotencyKey":       "550e8400-e29b-41d4-a716-446655440000"
}
```

**Success response (201)**
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

**All response codes**

| Status | Code | When |
|--------|------|------|
| 201 | — | Transfer completed successfully |
| 400 | `INVALID_JSON` | Request body is not valid JSON |
| 401 | — | Missing or invalid JWT token |
| 403 | `ACCOUNT_NOT_ACTIVE` | Account is frozen or closed |
| 404 | `ACCOUNT_NOT_FOUND` | Source or destination account does not exist |
| 409 | `DUPLICATE_REQUEST` | Same idempotency key is already being processed |
| 422 | `VALIDATION_ERROR` | Input validation failed (see `errors` field) |
| 422 | `INSUFFICIENT_FUNDS` | Source account balance too low |
| 422 | `CURRENCY_MISMATCH` | Account currencies do not match requested currency |
| 422 | `SELF_TRANSFER` | Source and destination accounts are the same |
| 503 | `SERVICE_UNAVAILABLE` | Could not acquire distributed lock — safe to retry |

**Idempotency**

Every request requires a unique `idempotencyKey` (UUID v4). Sending the same key twice returns the original response without processing the transfer again. Safe to retry on network failures — just reuse the same key.

## Get a JWT token

```bash
# Windows
php bin/console lexik:jwt:generate-token api_user

# Capture directly into a variable (PowerShell)
$token = (php bin/console lexik:jwt:generate-token api_user 2>$null | Select-String "^ey") -replace '^\s+|\s+$',''
```

Tokens expire after 1 hour (configurable via `JWT_TTL` in `.env`).

## Make a transfer (curl)

```bash
curl -X POST http://localhost:8080/api/v1/transfers \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "sourceAccountId":      "11111111-1111-4111-8111-111111111111",
    "destinationAccountId": "22222222-2222-4222-8222-222222222222",
    "amount":               "100.00",
    "currency":             "USD",
    "idempotencyKey":       "550e8400-e29b-41d4-a716-446655440000"
  }'
```

## Running tests

```bash
# Unit tests — no DB or Redis required
php vendor/bin/phpunit tests/Unit/ --testdox

# Integration tests — requires MySQL test DB and Redis
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php vendor/bin/phpunit tests/Integration/ --testdox

# Concurrent test — requires live server running on port 8080
$env:TEST_JWT_TOKEN        = (php bin/console lexik:jwt:generate-token api_user 2>$null | Select-String "^ey") -replace '^\s+|\s+$',''
$env:TEST_SOURCE_ACCOUNT_ID = "11111111-1111-4111-8111-111111111111"
$env:TEST_DEST_ACCOUNT_ID   = "22222222-2222-4222-8222-222222222222"
php vendor/bin/phpunit tests/Concurrent/ --group concurrent --testdox

# All tests at once
php vendor/bin/phpunit --testdox
```

## Project structure

```
src/
├── Controller/Api/V1/TransferController.php   # HTTP layer — deserialize, validate, delegate
├── DTO/TransferRequest.php                    # Immutable validated input object
├── Entity/
│   ├── Account.php                            # Balance with bcmath debit/credit
│   ├── AccountStatus.php                      # active | frozen | closed
│   ├── Transaction.php                        # Immutable ledger record
│   └── TransactionStatus.php                  # pending | completed | failed | reversed
├── Service/
│   ├── TransferService.php                    # Core business logic
│   ├── IdempotencyService.php                 # Redis deduplication
│   └── DistributedLockService.php             # Redis distributed lock
├── Repository/
│   ├── AccountRepository.php                  # findMultipleWithPessimisticLock
│   └── TransactionRepository.php
├── Message/TransferCompletedMessage.php       # Async event payload
├── MessageHandler/TransferCompletedHandler.php # Notifications, webhooks
├── EventListener/ApiExceptionListener.php     # Uniform JSON error responses
└── Exception/                                 # Domain exceptions

config/
├── packages/                                  # Symfony bundle configuration
│   ├── doctrine.yaml
│   ├── messenger.yaml
│   ├── security.yaml
│   ├── monolog.yaml
│   └── lexik_jwt_authentication.yaml
└── routes/

tests/
├── Unit/Service/TransferServiceTest.php       # Pure business logic, no I/O
├── Integration/Controller/TransferControllerTest.php  # Full HTTP stack
└── Concurrent/ConcurrentTransferTest.php      # Parallel requests, no double-spend
```

## Design decisions

**Pessimistic locking over optimistic**
: `SELECT FOR UPDATE` is used instead of `@Version` optimistic locking. Under high concurrency on shared accounts, optimistic locking causes retry storms — every failed attempt must re-read and re-execute from scratch. Pessimistic locking serialises at the row level; the second transaction waits a few milliseconds and succeeds immediately.

**Two-layer locking**
: A Redis distributed lock wraps the DB transaction. The Redis lock prevents multiple application nodes from even starting a transaction on the same account pair simultaneously, reducing DB lock contention. The `SELECT FOR UPDATE` is the final safety net if Redis fails.

**Deadlock prevention**
: Account IDs are sorted alphabetically before acquiring any lock (both Redis key and SQL `ORDER BY`). This ensures an A→B transfer and a B→A transfer always compete for locks in the same order, eliminating circular wait deadlocks.

**bcmath for all arithmetic**
: PHP floats use IEEE 754 binary64 — `0.1 + 0.2 !== 0.3`. Every balance calculation uses `bcadd`, `bcsub`, `bccomp` with `scale=8`. MySQL stores balances as `DECIMAL(20,8)`, which Doctrine maps to PHP `string` — never cast to float.

**UUID v7 for transaction IDs**
: UUID v7 is time-ordered. This keeps the `transactions` primary key index clustered, avoiding the random-write amplification that UUID v4 causes on large InnoDB tables.

**Post-commit async dispatch**
: Notifications and webhooks are dispatched via Symfony Messenger *after* the DB transaction commits. The transfer response is returned in ~10ms regardless of downstream latency. If a notification fails, it is retried independently without affecting the transfer result.


