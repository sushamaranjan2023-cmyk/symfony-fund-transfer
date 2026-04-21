<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Integration\ApiTestCase;

class TransferControllerTest extends ApiTestCase
{
    public function testSuccessfulTransferReturns201(): void
    {
        $this->post('/api/v1/transfers', [
            'sourceAccountId'      => self::SOURCE_ID,
            'destinationAccountId' => self::DEST_ID,
            'amount'               => '100.00',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000001-0000-4000-8000-900000000001',
        ]);

        $this->assertSame(201, $this->getStatusCode());
        $data = $this->getResponseData();
        
        $this->assertSame('completed', $data['status']);
        $this->assertSame('100.00', $data['amount']);
        $this->assertSame('USD', $data['currency']);
        $this->assertArrayHasKey('transaction_id', $data);
        $this->assertArrayHasKey('completed_at', $data);
        $this->assertSame('900.00000000', $this->getAccountBalance(self::SOURCE_ID));
        $this->assertSame('600.00000000', $this->getAccountBalance(self::DEST_ID));
        $this->assertSame(1, $this->countTransactions());
    }

    public function testIdempotentReplayReturnsSameResponse(): void
    {
        $body = [
            'sourceAccountId'      => self::SOURCE_ID,
            'destinationAccountId' => self::DEST_ID,
            'amount'               => '50.00',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000002-0000-4000-8000-000000000002',
        ];

        $this->post('/api/v1/transfers', $body);
        $this->assertSame(201, $this->getStatusCode());
        $first = $this->getResponseData();

        $this->post('/api/v1/transfers', $body);
        $second = $this->getResponseData();

        $this->assertSame($first['transaction_id'], $second['transaction_id']);
        $this->assertSame('950.00000000', $this->getAccountBalance(self::SOURCE_ID));
        $this->assertSame(1, $this->countTransactions());
    }

    public function testInsufficientFundsReturns422(): void
    {
        $this->post('/api/v1/transfers', [
            'sourceAccountId'      => self::SOURCE_ID,
            'destinationAccountId' => self::DEST_ID,
            'amount'               => '9999.00',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000003-0000-4000-8000-000000000003',
        ]);

        $this->assertSame(422, $this->getStatusCode());
        $this->assertSame('INSUFFICIENT_FUNDS', $this->getResponseData()['code']);
        $this->assertSame('1000.00000000', $this->getAccountBalance(self::SOURCE_ID));
        $this->assertSame(0, $this->countTransactions());
    }

    public function testSelfTransferReturns422(): void
    {
        $this->post('/api/v1/transfers', [
            'sourceAccountId'      => self::SOURCE_ID,
            'destinationAccountId' => self::SOURCE_ID,
            'amount'               => '10.00',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000004-0000-4000-8000-000000000004',
        ]);

        $this->assertSame(422, $this->getStatusCode());
        $this->assertSame('SELF_TRANSFER', $this->getResponseData()['code']);
    }

    public function testCurrencyMismatchReturns422(): void
    {
        $this->post('/api/v1/transfers', [
            'sourceAccountId'      => self::SOURCE_ID,
            'destinationAccountId' => self::EUR_ID,
            'amount'               => '10.00',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000005-0000-4000-8000-000000000005',
        ]);

        $this->assertSame(422, $this->getStatusCode());
        $this->assertSame('CURRENCY_MISMATCH', $this->getResponseData()['code']);
    }

    public function testFrozenAccountReturns403(): void
    {
        $this->post('/api/v1/transfers', [
            'sourceAccountId'      => self::FROZEN_ID,
            'destinationAccountId' => self::DEST_ID,
            'amount'               => '10.00',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000006-0000-4000-8000-000000000006',
        ]);

        $this->assertSame(403, $this->getStatusCode());
        $this->assertSame('ACCOUNT_NOT_ACTIVE', $this->getResponseData()['code']);
    }

    public function testNonExistentAccountReturns404(): void
    {
        $this->post('/api/v1/transfers', [
            'sourceAccountId'      => '00000000-0000-4000-8000-000000000000',
            'destinationAccountId' => self::DEST_ID,
            'amount'               => '10.00',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000007-0000-4000-8000-000000000007',
        ]);

        $this->assertSame(404, $this->getStatusCode());
        $this->assertSame('ACCOUNT_NOT_FOUND', $this->getResponseData()['code']);
    }

    public function testMissingFieldsReturns422(): void
    {
        $this->post('/api/v1/transfers', ['amount' => '10.00']);

        $this->assertSame(422, $this->getStatusCode());
        $this->assertSame('VALIDATION_ERROR', $this->getResponseData()['code']);
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->client->request(
            'POST', '/api/v1/transfers', [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json'],
            '{bad json'
        );

        $this->assertSame(400, $this->getStatusCode());
        $this->assertSame('INVALID_JSON', $this->getResponseData()['code']);
    }

    public function testMissingTokenReturns401(): void
    {
        $this->client->request(
            'POST', '/api/v1/transfers', [], [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}'
        );

        $this->assertSame(401, $this->getStatusCode());
    }

    public function testInvalidTokenReturns401(): void
    {
        $this->post('/api/v1/transfers', [
            'sourceAccountId'      => self::SOURCE_ID,
            'destinationAccountId' => self::DEST_ID,
            'amount'               => '10.00',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000008-0000-4000-8000-000000000008',
        ], 'invalid.jwt.token');

        $this->assertSame(401, $this->getStatusCode());
    }

    public function testSmallDecimalAmountHandledExactly(): void
    {
        $this->post('/api/v1/transfers', [
            'sourceAccountId'      => self::SOURCE_ID,
            'destinationAccountId' => self::DEST_ID,
            'amount'               => '0.00000001',
            'currency'             => 'USD',
            'idempotencyKey'       => '00000009-0000-4000-8000-000000000009',
        ]);

        $this->assertSame(201, $this->getStatusCode());
        $this->assertSame('999.99999999', $this->getAccountBalance(self::SOURCE_ID));
        $this->assertSame('500.00000001', $this->getAccountBalance(self::DEST_ID));
    }
}