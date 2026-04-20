# Transfer API Flow

This document explains the transfer flow for `POST /api/v1/transfers` in this project.

## 1. Controller entry point

File: `src/Controller/Api/V1/TransferController.php`

- Endpoint: `POST /api/v1/transfers`
- Reads raw JSON request body.
- Deserializes into `App\DTO\TransferRequest`.
- Validates the DTO using Symfony Validator.
- Calls `App\Service\TransferService::execute()`.
- Converts exceptions into structured JSON responses with proper HTTP status codes.

## 2. Request DTO

File: `src/DTO/TransferRequest.php`

Fields:
- `sourceAccountId`
- `destinationAccountId`
- `amount`
- `currency`
- `idempotencyKey`

Validation rules:
- Source and destination must be valid UUIDs.
- Amount must be positive and a valid decimal string.
- Currency must be a valid ISO 4217 code.
- Idempotency key must be a valid UUID.

Additional check:
- `isSelfTransfer()` rejects transfers where source and destination are identical.

## 3. Transfer business logic

File: `src/Service/TransferService.php`

The `execute()` method implements the transfer workflow:

1. Self-transfer guard
   - Throws `SelfTransferException` if source and destination accounts are equal.

2. Idempotency check
   - `IdempotencyService::check()` reads Redis state for the idempotency key.
   - If the key is `IN_PROGRESS`, a duplicate request is already running.
   - If the key is `COMPLETE`, the cached response is returned.
   - Otherwise the request is marked `IN_PROGRESS` using `markInProgress()`.

3. Distributed lock
   - Uses `DistributedLockService` and a Redis lock key for the account pair.
   - The lock key is sorted to avoid deadlocks between reversed transfer directions.
   - If the lock cannot be acquired, the request fails safely.

4. Database transaction
   - `runInTransaction()` executes transfer logic inside a DB transaction.
   - Accounts are loaded with pessimistic write locks (`SELECT FOR UPDATE`).

5. Account validation
   - Ensures both accounts exist.
   - Ensures both accounts are active.
   - Ensures both accounts use the requested currency.

6. Debit and credit
   - `Account::debit()` withdraws from the source account.
   - `Account::credit()` deposits into the destination account.
   - Uses `bcmath` arithmetic with string values to preserve precision.
   - Throws `InsufficientFundsException` if the source balance is too low.

7. Persist transaction
   - Creates a `Transaction` entity with status `Completed`.
   - Saves the transaction in the same DB transaction.

8. Audit logging
   - Writes a structured log entry for the completed transfer.

9. Async post-commit event
   - Dispatches `TransferCompletedMessage` via Symfony Messenger.
   - This is processed asynchronously after the transfer commits.

10. Finalize idempotency state
    - On success, `markComplete()` stores the response in Redis for 24 hours.
    - On failure, `markFailed()` removes the key so the client can retry.

## 4. Account entity

File: `src/Entity/Account.php`

Key behavior:
- Balance stored as a decimal string.
- `debit()` subtracts using `bcsub()` and rejects negative balances.
- `credit()` adds using `bcadd()`.
- `isActive()` checks account status.
- Uses lifecycle callbacks to update `updatedAt`.

## 5. Transaction entity

File: `src/Entity/Transaction.php`

Key properties:
- `id`
- `idempotencyKey`
- `sourceAccountId`
- `destAccountId`
- `amount`
- `currency`
- `status`
- `initiatedBy`
- `ipAddress`
- `createdAt`
- `completedAt`

Important constraint:
- `idempotency_key` is unique to prevent duplicate transaction records.

## 6. Async completion handler

Files:
- `src/Message/TransferCompletedMessage.php`
- `src/MessageHandler/TransferCompletedHandler.php`

Flow:
- `TransferCompletedMessage` carries the `transactionId`.
- `TransferCompletedHandler` loads the transaction and logs processing.
- This is the extension point for notifications, webhooks, or reconciliation.

## 7. Error handling

The controller maps known exceptions to JSON responses:
- `DuplicateTransferException` → `409 Conflict`
- `AccountNotFoundException` → `404 Not Found`
- `AccountNotActiveException` → `403 Forbidden`
- `InsufficientFundsException` → `422 Unprocessable Entity`
- `CurrencyMismatchException` → `422 Unprocessable Entity`
- `SelfTransferException` → `422 Unprocessable Entity`
- `RuntimeException` → `503 Service Unavailable`
- Other exceptions → `500 Internal Server Error`

## 8. Overall end-to-end flow

1. Client sends `POST /api/v1/transfers`.
2. Controller deserializes and validates the input.
3. Service checks idempotency and acquires a distributed lock.
4. Service opens a DB transaction and locks both accounts.
5. Service validates accounts, debits source, credits destination.
6. Service persists a transaction record and logs the event.
7. Service dispatches an async completion message.
8. Controller returns the transfer result.

---

This file summarizes how request validation, idempotency, locking, transaction persistence, and async post-processing work together in the transfer API.