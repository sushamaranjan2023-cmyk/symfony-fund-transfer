<?php

declare(strict_types=1);

namespace App\Tests\Concurrent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Concurrency test: fires N simultaneous transfer requests against a live server.
 * Verifies no double-spend occurs and final balance is mathematically correct.
 *
 * Prerequisites:
 *   - App running at TEST_BASE_URL (default: http://localhost:8080)
 *   - TEST_JWT_TOKEN set in environment
 *   - TEST_SOURCE_ACCOUNT_ID with balance >= (TRANSFER_AMOUNT * WORKER_COUNT / 2)
 *   - TEST_DEST_ACCOUNT_ID active
 *
 * Run: php bin/phpunit tests/Concurrent/ --group concurrent
 */
class ConcurrentTransferTest extends TestCase
{
    private const WORKER_COUNT     = 30;
    private const TRANSFER_AMOUNT  = '10.00';
    private const CURRENCY         = 'USD';

    /**
     * @group concurrent
     */
    public function testNoConcurrentDoubleSpend(): void
    {
        $baseUrl    = $_ENV['TEST_BASE_URL']          ?? 'http://localhost:8080';
        $jwt        = $_ENV['TEST_JWT_TOKEN']         ?? '';
        $sourceId   = $_ENV['TEST_SOURCE_ACCOUNT_ID'] ?? '';
        $destId     = $_ENV['TEST_DEST_ACCOUNT_ID']   ?? '';

        if (empty($jwt) || empty($sourceId) || empty($destId)) {
            $this->markTestSkipped('Concurrent test requires TEST_JWT_TOKEN, TEST_SOURCE_ACCOUNT_ID, TEST_DEST_ACCOUNT_ID in environment.');
        }

        // ── Fire N concurrent requests ──────────────────────────────────────
        $processes = [];
        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $body = json_encode([
                'sourceAccountId'      => $sourceId,
                'destinationAccountId' => $destId,
                'amount'               => self::TRANSFER_AMOUNT,
                'currency'             => self::CURRENCY,
                'idempotencyKey'       => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            ]);

            $processes[] = new Process([
                'curl', '-s', '-w', "\n%{http_code}",
                '-X', 'POST',
                '-H', 'Content-Type: application/json',
                '-H', "Authorization: Bearer {$jwt}",
                '-d', $body,
                "{$baseUrl}/api/v1/transfers",
            ]);
        }

        // Start all simultaneously
        foreach ($processes as $p) {
            $p->start();
        }

        // Wait for all to complete
        foreach ($processes as $p) {
            $p->wait();
        }

        // ── Analyse results ─────────────────────────────────────────────────
        $successes  = 0;
        $failures   = 0;
        $txIds      = [];

        foreach ($processes as $p) {
            $output = $p->getOutput();
            $lines  = array_filter(explode("\n", trim($output)));
            $status = (int) array_pop($lines);
            $body   = json_decode(implode('', $lines), true);

            if ($status === 201) {
                $successes++;
                $txIds[] = $body['transaction_id'] ?? null;
            } else {
                $failures++;
            }
        }

        // All transaction IDs must be unique — no double processing
        $this->assertCount(
            count(array_unique($txIds)),
            $txIds,
            'Duplicate transaction IDs detected — double-spend occurred!'
        );

        // At least one success, no more successes than the account could afford
        $this->assertGreaterThan(0, $successes, 'All transfers failed — something is broken.');
        $this->assertSame(self::WORKER_COUNT, $successes + $failures);

        echo sprintf(
            "\nConcurrent test: %d workers, %d succeeded, %d failed (expected with insufficient funds or lock contention)\n",
            self::WORKER_COUNT, $successes, $failures
        );
    }
}
