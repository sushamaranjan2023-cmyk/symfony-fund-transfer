<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()
            ->get('doctrine')
            ->getManager();

        // Clean DB before each test
        $this->em->getConnection()->executeStatement('DELETE FROM transactions');
        $this->em->getConnection()->executeStatement('DELETE FROM accounts');

        // Seed fresh accounts
        $this->em->getConnection()->executeStatement("
            INSERT INTO accounts (id, owner_id, currency, balance, status, version, created_at, updated_at)
            VALUES
            ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'owner-1', 'USD', '1000.00000000', 'active', 0, NOW(6), NOW(6)),
            ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'owner-2', 'USD', '500.00000000',  'active', 0, NOW(6), NOW(6)),
            ('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'owner-3', 'EUR', '200.00000000',  'active', 0, NOW(6), NOW(6)),
            ('dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'owner-4', 'USD', '0.00000000',    'frozen', 0, NOW(6), NOW(6))
        ");

        // Generate JWT token
        $this->token = $this->generateToken();
    }

    protected function post(string $uri, array $body, ?string $token = null): void
    {
        $this->client->request(
            'POST',
            $uri,
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token),
                'CONTENT_TYPE'       => 'application/json',
            ],
            json_encode($body)
        );
    }

    protected function getResponseData(): array
    {
        return json_decode(
            $this->client->getResponse()->getContent(),
            true
        );
    }

    protected function getStatusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function getAccountBalance(string $accountId): string
    {
        $this->em->clear();
        return $this->em->getConnection()
            ->fetchOne('SELECT balance FROM accounts WHERE id = ?', [$accountId]);
    }

    protected function countTransactions(): int
    {
        return (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM transactions');
    }

    private function generateToken(): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $user = new \Symfony\Component\Security\Core\User\InMemoryUser('api_user', null, ['ROLE_USER']);
        return $jwtManager->create($user);
    }

    // Fixed account IDs for tests
    protected const SOURCE_ID  = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    protected const DEST_ID    = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    protected const EUR_ID     = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    protected const FROZEN_ID  = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
}