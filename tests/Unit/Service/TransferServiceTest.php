<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Entity\AccountStatus;
use App\Exception\AccountNotFoundException;
use App\Exception\InsufficientFundsException;
use App\Exception\SelfTransferException;
use App\Exception\CurrencyMismatchException;
use App\Message\TransferCompletedMessage;
use App\Repository\AccountRepository;
use App\Service\DistributedLockService;
use App\Service\IdempotencyService;
use App\Service\TransferService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

class TransferServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AccountRepository&MockObject      $accountRepo;
    private IdempotencyService&MockObject     $idempotency;
    private DistributedLockService&MockObject $lockService;
    private MessageBusInterface&MockObject    $bus;
    private TransferService                   $service;
    private UserInterface&MockObject          $user;

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->accountRepo = $this->createMock(AccountRepository::class);
        $this->idempotency = $this->createMock(IdempotencyService::class);
        $this->lockService = $this->createMock(DistributedLockService::class);
        $this->bus         = $this->createMock(MessageBusInterface::class);
        $this->user        = $this->createMock(UserInterface::class);

        $this->user->method('getUserIdentifier')->willReturn('test_user');

        $this->service = new TransferService(
            $this->em,
            $this->accountRepo,
            $this->idempotency,
            $this->lockService,
            $this->bus,
            new NullLogger(),
        );
    }

    public function testSuccessfulTransfer(): void
    {
        $sourceId = (string) Uuid::v4();
        $destId   = (string) Uuid::v4();
        $dto      = $this->makeDto($sourceId, $destId, '50.00', 'USD');

        $source = $this->makeAccount($sourceId, 'USD', '100.00000000');
        $dest   = $this->makeAccount($destId, 'USD', '0.00000000');

        $this->idempotency->method('check')->willReturn(false);
        $this->idempotency->method('markInProgress')->willReturn(true);
        $this->lockService->method('acquire')->willReturn('lock_token_abc');

        $this->accountRepo
            ->method('findMultipleWithPessimisticLock')
            ->willReturn([$source, $dest]);

        $this->em
            ->method('wrapInTransaction')
            ->willReturnCallback(fn(callable $cb) => $cb());

        $this->em->expects($this->once())->method('persist');
        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TransferCompletedMessage::class))
            ->willReturn(new Envelope(new TransferCompletedMessage('id')));

        $result = $this->service->execute($dto, $this->user);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('50.00', $result['amount']);
        $this->assertSame('USD', $result['currency']);
        $this->assertArrayHasKey('transaction_id', $result);

        // Verify balances were updated correctly
        $this->assertSame('50.00000000', $source->getBalance());
        $this->assertSame('50.00000000', $dest->getBalance());
    }

    public function testInsufficientFundsThrows(): void
    {
        $sourceId = (string) Uuid::v4();
        $destId   = (string) Uuid::v4();
        $dto      = $this->makeDto($sourceId, $destId, '200.00', 'USD');

        $source = $this->makeAccount($sourceId, 'USD', '100.00000000');
        $dest   = $this->makeAccount($destId, 'USD', '0.00000000');

        $this->idempotency->method('check')->willReturn(false);
        $this->idempotency->method('markInProgress')->willReturn(true);
        $this->lockService->method('acquire')->willReturn('lock_token');

        $this->accountRepo->method('findMultipleWithPessimisticLock')->willReturn([$source, $dest]);
        $this->em->method('wrapInTransaction')->willReturnCallback(fn(callable $cb) => $cb());

        $this->expectException(InsufficientFundsException::class);

        $this->service->execute($dto, $this->user);
    }

    public function testSelfTransferThrows(): void
    {
        $id  = (string) Uuid::v4();
        $dto = $this->makeDto($id, $id, '10.00', 'USD');

        $this->expectException(SelfTransferException::class);
        $this->service->execute($dto, $this->user);
    }

    public function testIdempotentReplayReturnsOriginalResponse(): void
    {
        $cachedResponse = ['transaction_id' => 'existing-id', 'status' => 'completed', 'amount' => '10.00', 'currency' => 'USD'];
        $this->idempotency->method('check')->willReturn($cachedResponse);

        $dto    = $this->makeDto((string) Uuid::v4(), (string) Uuid::v4(), '10.00', 'USD');
        $result = $this->service->execute($dto, $this->user);

        $this->assertSame($cachedResponse, $result);
        $this->em->expects($this->never())->method('wrapInTransaction');
    }

    public function testCurrencyMismatchThrows(): void
    {
        $sourceId = (string) Uuid::v4();
        $destId   = (string) Uuid::v4();
        $dto      = $this->makeDto($sourceId, $destId, '10.00', 'EUR');

        $source = $this->makeAccount($sourceId, 'USD', '100.00000000');
        $dest   = $this->makeAccount($destId, 'USD', '0.00000000');

        $this->idempotency->method('check')->willReturn(false);
        $this->idempotency->method('markInProgress')->willReturn(true);
        $this->lockService->method('acquire')->willReturn('lock_token');

        $this->accountRepo->method('findMultipleWithPessimisticLock')->willReturn([$source, $dest]);
        $this->em->method('wrapInTransaction')->willReturnCallback(fn(callable $cb) => $cb());

        $this->expectException(CurrencyMismatchException::class);

        $this->service->execute($dto, $this->user);
    }

    public function testSourceAccountNotFoundThrows(): void
    {
        $sourceId = (string) Uuid::v4();
        $destId   = (string) Uuid::v4();
        $dto      = $this->makeDto($sourceId, $destId, '10.00', 'USD');

        $dest = $this->makeAccount($destId, 'USD', '0.00000000');

        $this->idempotency->method('check')->willReturn(false);
        $this->idempotency->method('markInProgress')->willReturn(true);
        $this->lockService->method('acquire')->willReturn('lock_token');

        // Only dest returned — source missing
        $this->accountRepo->method('findMultipleWithPessimisticLock')->willReturn([$dest]);
        $this->em->method('wrapInTransaction')->willReturnCallback(fn(callable $cb) => $cb());

        $this->expectException(AccountNotFoundException::class);

        $this->service->execute($dto, $this->user);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeDto(string $src, string $dest, string $amount, string $currency): TransferRequest
    {
        return new TransferRequest(
            sourceAccountId:      $src,
            destinationAccountId: $dest,
            amount:               $amount,
            currency:             $currency,
            idempotencyKey:       (string) Uuid::v4(),
        );
    }

    private function makeAccount(string $id, string $currency, string $balance): Account
    {
        $account = new Account(
            id:             $id,
            ownerId:        (string) Uuid::v4(),
            currency:       $currency,
            initialBalance: $balance,
        );
        return $account;
    }
}
