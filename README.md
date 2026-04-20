# Fund Transfer API — Symfony 7

REST API for secure fund transfers between accounts.

## Architecture at a glance

| Layer | Technology | Purpose |
|---|---|---|
| HTTP | Symfony 7 + JWT | Auth, routing, validation |
| Business logic | TransferService | Debit/credit, rules |
| Idempotency | Redis SET NX | Deduplicate requests |
| Distributed lock | Redis Lua CAS | Serialise concurrent transfers |
| Data integrity | MySQL InnoDB + SELECT FOR UPDATE | ACID, pessimistic locking |
| Async | Symfony Messenger + Redis | Post-commit notifications |
| Audit | Monolog JSON (audit channel) | Structured audit trail |

## Transfer flow documentation

For a detailed, step-by-step explanation of the transfer lifecycle, see `TRANSFER_FLOW.md`.

## Quick start (Docker)

```bash
# 1. Clone and enter the project
cd fund-transfer-api

# 2. Copy .env.example to .env (Docker will use this)
cp .env.example .env

# 3. Start all services
docker compose up -d

# 4. Wait for MySQL to be healthy, then run migrations
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# 5. Generate JWT keys
docker compose exec app php bin/console lexik:jwt:generate-keypair

# 6. Get a JWT token (replace with your auth flow)
docker compose exec app php bin/console lexik:jwt:generate-token api_user

# 7. Make a transfer
curl -X POST http://localhost:8080/api/v1/transfers \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "sourceAccountId":      "YOUR_SOURCE_UUID",
    "destinationAccountId": "YOUR_DEST_UUID",
    "amount":               "50.00",
    "currency":             "USD",
    "idempotencyKey":       "550e8400-e29b-41d4-a716-446655440000"
  }'
```


## Run tests

```bash
# Unit tests only (no infrastructure required)
docker compose exec app php bin/phpunit --testsuite unit

# Integration tests (requires running DB + Redis)
docker compose exec app php bin/phpunit --testsuite integration

# Concurrent test (requires running app + seeded accounts + TEST_JWT_TOKEN)
TEST_JWT_TOKEN=xxx \
TEST_SOURCE_ACCOUNT_ID=11111111-1111-4111-8111-111111111111 \
TEST_DEST_ACCOUNT_ID=22222222-2222-4222-8222-222222222222 \
docker compose exec app php bin/phpunit --testsuite concurrent
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
  "sourceAccountId":      "uuid-v4",
  "destinationAccountId": "uuid-v4",
  "amount":               "150.00",
  "currency":             "USD",
  "idempotencyKey":       "uuid-v4"
}
```

**Responses**

| Status | Code | Meaning |
|--------|------|---------|
| 201 | — | Transfer completed |
| 200 | — | Idempotent replay (same response as first call) |
| 400 | INVALID_JSON | Malformed JSON body |
| 401 | — | Missing or invalid JWT |
| 404 | ACCOUNT_NOT_FOUND | Source or destination account does not exist |
| 403 | ACCOUNT_NOT_ACTIVE | Account is frozen or closed |
| 409 | DUPLICATE_REQUEST | Same idempotency key is being processed concurrently |
| 422 | VALIDATION_ERROR | Input validation failed |
| 422 | INSUFFICIENT_FUNDS | Source balance too low |
| 422 | CURRENCY_MISMATCH | Account currencies don't match requested currency |
| 422 | SELF_TRANSFER | Source and destination are the same account |
| 503 | SERVICE_UNAVAILABLE | Could not acquire distributed lock — retry |

**Success response (201)**
```json
{
  "transaction_id": "018fae3d-0000-7000-8000-000000000099",
  "status":         "completed",
  "amount":         "150.00",
  "currency":       "USD",
  "source_account": "uuid",
  "dest_account":   "uuid",
  "completed_at":   "2024-01-01T12:00:00+00:00"
}
```

## Key design decisions

### Pessimistic locking (SELECT FOR UPDATE)

Chosen over optimistic locking because high-frequency transfers on shared accounts would cause optimistic lock collisions and expensive retry storms. Pessimistic locking serialises at the row level — the second concurrent transaction simply waits a few ms and succeeds on its first attempt.

### Two-layer locking

Redis distributed lock → MySQL SELECT FOR UPDATE. The Redis lock prevents two app pods from even starting a DB transaction on the same account pair simultaneously, reducing DB contention. The MySQL lock is the final guard if Redis fails.

### Deadlock prevention

All locks (both Redis key and SQL ORDER BY) sort account IDs alphabetically before acquisition. This ensures A→B and B→A transfers compete for the same lock in the same order, eliminating AB/BA circular waits.

### bcmath everywhere

PHP floats use IEEE 754 binary64. `0.1 + 0.2 !== 0.3`. All balance arithmetic uses `bcadd`, `bcsub`, `bccomp` with scale=8. MySQL stores `DECIMAL(20,8)` which Doctrine maps to `string` — never cast to float.

### UUID v7 for transaction IDs

Time-ordered, keeping the `transactions` index clustered and avoiding random-write amplification (an InnoDB penalty for UUID v4 primary keys on large tables).

### Post-commit Messenger dispatch

Notifications and webhooks dispatch via Symfony Messenger *after* the DB transaction commits. This ensures side effects only fire for successful transfers, and the HTTP response is returned in ~10ms regardless of downstream latency.

## Environment variables

| Variable | Description |
|---|---|
| `DATABASE_URL` | MySQL DSN |
| `REDIS_DSN` | Redis DSN |
| `JWT_SECRET_KEY` | Path to private PEM key |
| `JWT_PUBLIC_KEY` | Path to public PEM key |
| `JWT_PASSPHRASE` | Key passphrase |
| `JWT_TTL` | Token lifetime in seconds |
| `MESSENGER_TRANSPORT_DSN` | Redis DSN for Messenger |

