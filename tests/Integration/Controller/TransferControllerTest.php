<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for POST /api/v1/transfers.
 *
 * Run with: php bin/phpunit tests/Integration/
 *
 * Prerequisites:
 *   - Test database seeded via fixtures or the setUp() helper below
 *   - Redis available at REDIS_DSN
 *   - Valid JWT token available (see getJwt())
 */
class TransferControllerTest extends WebTestCase
{
    private const ENDPOINT = '/api/v1/transfers';

    public function testSuccessfulTransferReturns201(): void
    {
        $client = static::createClient();

        $response = $this->postTransfer($client, [
            'sourceAccountId'      => $this->getSeededSourceAccountId(),
            'destinationAccountId' => $this->getSeededDestAccountId(),
            'amount'               => '10.00',
            'currency'             => 'USD',
            'idempotencyKey'       => (string) Uuid::v4(),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('transaction_id', $data);
        $this->assertSame('completed', $data['status']);
    }

    public function testIdempotentReplayReturns201WithSameBody(): void
    {
        $client         = static::createClient();
        $idempotencyKey = (string) Uuid::v4();
        $payload        = [
            'sourceAccountId'      => $this->getSeededSourceAccountId(),
            'destinationAccountId' => $this->getSeededDestAccountId(),
            'amount'               => '5.00',
            'currency'             => 'USD',
            'idempotencyKey'       => $idempotencyKey,
        ];

        $this->postTransfer($client, $payload);
        $firstBody = $client->getResponse()->getContent();

        // Send the exact same request again
        $this->postTransfer($client, $payload);
        $secondBody = $client->getResponse()->getContent();

        $this->assertSame($firstBody, $secondBody);
    }

    public function testMissingBodyReturns400(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            self::ENDPOINT,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->getJwt(), 'CONTENT_TYPE' => 'application/json'],
            '{bad json'
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testMissingFieldsReturns422(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            self::ENDPOINT,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->getJwt(), 'CONTENT_TYPE' => 'application/json'],
            json_encode(['amount' => '10.00'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $client = static::createClient();
        $client->request('POST', self::ENDPOINT, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testInsufficientFundsReturns422(): void
    {
        $client   = static::createClient();
        $response = $this->postTransfer($client, [
            'sourceAccountId'      => $this->getSeededSourceAccountId(),
            'destinationAccountId' => $this->getSeededDestAccountId(),
            'amount'               => '999999999.00',  // way over any balance
            'currency'             => 'USD',
            'idempotencyKey'       => (string) Uuid::v4(),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INSUFFICIENT_FUNDS', $data['code']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function postTransfer($client, array $payload): void
    {
        $client->request(
            'POST',
            self::ENDPOINT,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->getJwt(), 'CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );
    }

    private function getJwt(): string
    {
        // In a real test suite, generate this via lexik:jwt:generate-token or a test fixture
        return $_ENV['TEST_JWT_TOKEN'] ?? 'replace_with_valid_test_jwt';
    }

    private function getSeededSourceAccountId(): string
    {
        return $_ENV['TEST_SOURCE_ACCOUNT_ID'] ?? 'replace_with_seeded_account_uuid';
    }

    private function getSeededDestAccountId(): string
    {
        return $_ENV['TEST_DEST_ACCOUNT_ID'] ?? 'replace_with_seeded_account_uuid';
    }
}
