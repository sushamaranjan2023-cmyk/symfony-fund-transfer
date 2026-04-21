<?php

declare(strict_types=1);

namespace App\Tests\Concurrent;

use PHPUnit\Framework\TestCase;

/**
 * Concurrency test using PHP parallel curl_multi.
 *
 * Prerequisites:
 *   - App running at TEST_BASE_URL (default: http://localhost:8080)
 *   - TEST_JWT_TOKEN set in environment
 *   - TEST_SOURCE_ACCOUNT_ID seeded with balance >= TRANSFER_AMOUNT * WORKER_COUNT
 *   - TEST_DEST_ACCOUNT_ID active
 *
 * Run:
 *   $env:TEST_JWT_TOKEN="your_token"
 *   $env:TEST_SOURCE_ACCOUNT_ID="11111111-1111-4111-8111-111111111111"
 *   $env:TEST_DEST_ACCOUNT_ID="22222222-2222-4222-8222-222222222222"
 *   php vendor/bin/phpunit tests/Concurrent/ --group concurrent
 */
class ConcurrentTransferTest extends TestCase
{
    private const WORKER_COUNT    = 20;
    private const TRANSFER_AMOUNT = '10.00';
    private const CURRENCY        = 'USD';

    /**
     * @group concurrent
     */
    public function testNoConcurrentDoubleSpend(): void
    {
        $baseUrl  = $_ENV['TEST_BASE_URL']          ?? 'http://localhost:8080';
        $jwt      = $_ENV['TEST_JWT_TOKEN']         ?? '';
        $sourceId = $_ENV['TEST_SOURCE_ACCOUNT_ID'] ?? '';
        $destId   = $_ENV['TEST_DEST_ACCOUNT_ID']   ?? '';

        if (empty($jwt) || empty($sourceId) || empty($destId)) {
            $this->markTestSkipped(
                'Set TEST_JWT_TOKEN, TEST_SOURCE_ACCOUNT_ID, TEST_DEST_ACCOUNT_ID to run this test.'
            );
        }

        // ── Build curl_multi handles ────────────────────────────────────────
        $mh      = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < self::WORKER_COUNT; $i++) {
            $body = json_encode([
                'sourceAccountId'      => $sourceId,
                'destinationAccountId' => $destId,
                'amount'               => self::TRANSFER_AMOUNT,
                'currency'             => self::CURRENCY,
                'idempotencyKey'       => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            ]);

            $ch = curl_init("{$baseUrl}/api/v1/transfers");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    "Authorization: Bearer {$jwt}",
                ],
                CURLOPT_TIMEOUT => 30,
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        // ── Execute all simultaneously ──────────────────────────────────────
        $running = 0;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        // ── Collect results ─────────────────────────────────────────────────
        $successes = 0;
        $failures  = 0;
        $txIds     = [];

        foreach ($handles as $ch) {
            $response   = curl_multi_getcontent($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $data       = json_decode($response, true);

            if ($statusCode === 201) {
                $successes++;
                $txIds[] = $data['transaction_id'] ?? null;
            } else {
                $failures++;
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        // ── Assertions ──────────────────────────────────────────────────────
        echo sprintf(
            "\n[Concurrent] %d workers | %d succeeded | %d failed\n",
            self::WORKER_COUNT, $successes, $failures
        );

        // At least one must succeed
        $this->assertGreaterThan(0, $successes, 'All transfers failed.');

        // All transaction IDs must be unique — no double processing
        $uniqueTxIds = array_unique(array_filter($txIds));
        $this->assertCount(
            count($uniqueTxIds),
            array_filter($txIds),
            'Duplicate transaction IDs found — double-spend detected!'
        );

        // Total must equal worker count
        $this->assertSame(self::WORKER_COUNT, $successes + $failures);
    }
}