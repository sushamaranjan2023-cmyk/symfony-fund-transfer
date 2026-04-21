# Fund Transfer API

REST API for secure fund transfers between accounts, built with Symfony 7, MySQL, and Redis.

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

## Get a JWT token

```bash
bin/console lexik:jwt:generate-token api_user

# TOKEN
$token = (php bin/console lexik:jwt:generate-token api_user 2>$null | Select-String "^ey") -replace '^\s+|\s+$',''
```

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
# Unit tests
php vendor/bin/phpunit tests/Unit/ --testdox

# Integration tests
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php vendor/bin/phpunit tests/Integration/ --testdox
```


## Approximate time spent
Time spent: ~6-7 hours

## Prompt

```
Act as a senior backend architect with deep expertise in PHP 8.3, Symfony 7, MySQL, and Redis.
Design and implement a production-ready API for secure fund transfers between accounts.
This is NOT a demo project. The solution must reflect real-world financial system design with high reliability, scalability, and data integrity.
### Functional Requirements:
- API endpoint: POST /api/v1/transfers
- Transfer funds between two accounts (debit + credit)
- Validate account existence, balance, and currency
- Prevent overdrafts
- Ensure idempotency (same request should not process twice)
### Non-Functional Requirements:
- Must handle high concurrency safely
- Ensure ACID compliance using MySQL transactions
- Prevent race conditions (e.g., double spending)
- Use Redis where appropriate (e.g., idempotency keys, locks, caching)
### Technical Requirements:
- Symfony 7 structure (controllers, services, DTOs, events, repositories)
- Use Doctrine ORM with proper transaction handling
- Implement pessimistic or optimistic locking (justify choice)
- Use Redis for distributed locking or idempotency keys
- Use Symfony Messenger for async processing (optional but preferred)
- Include proper validation using Symfony Validator
- Use environment-based config and secrets management
### Security Requirements:
- Authentication (JWT or API key)
- Input validation and sanitization
- Prevent replay attacks
- Rate limiting
- Logging and audit trail
### Observability:
- Structured logging (Monolog)
- Error handling strategy
- Metrics or tracing suggestions
### Testing:
- Unit tests for business logic
- Integration tests for API endpoint
- Simulate concurrent transfers
### Deliverables:
1. Folder structure
2. Database schema (accounts, transactions)
3. Key code snippets (Controller, Service, Repository, Locking logic)
4. Example request/response
5. Explanation of design decisions
6. Edge cases handled
7. How to run locally (Docker preferred)
### Important:
- Focus on correctness, consistency, and robustness over simplicity
- Avoid shortcuts that break under concurrency
- Explain WHY each design choice is made

```


