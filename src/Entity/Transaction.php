<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
#[ORM\UniqueConstraint(name: 'uq_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(columns: ['source_account_id'], name: 'idx_source')]
#[ORM\Index(columns: ['dest_account_id'], name: 'idx_dest')]
#[ORM\Index(columns: ['status'], name: 'idx_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created')]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'source_account_id', type: 'string', length: 36)]
    private string $sourceAccountId;

    #[ORM\Column(name: 'dest_account_id', type: 'string', length: 36)]
    private string $destAccountId;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 8)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'string', enumType: TransactionStatus::class)]
    private TransactionStatus $status;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: 'string', length: 36)]
    private string $initiatedBy;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        string $id,
        string $idempotencyKey,
        Account $sourceAccount,
        Account $destAccount,
        string $amount,
        string $currency,
        TransactionStatus $status,
        string $initiatedBy,
        ?string $ipAddress = null,
    ) {
        $this->id              = (string) $id;
        $this->idempotencyKey  = $idempotencyKey;
        $this->sourceAccountId = (string) $sourceAccount->getId();
        $this->destAccountId   = (string) $destAccount->getId();
        $this->amount          = $amount;
        $this->currency        = $currency;
        $this->status          = $status;
        $this->initiatedBy     = $initiatedBy;
        $this->ipAddress       = $ipAddress;
        $this->createdAt       = new \DateTimeImmutable();

        if ($status === TransactionStatus::Completed) {
            $this->completedAt = new \DateTimeImmutable();
        }
    }

    public function markFailed(string $reason): void
    {
        $this->status        = TransactionStatus::Failed;
        $this->failureReason = $reason;
    }

    // ── Getters ──────────────────────────────────────────────────────────────

    public function getId(): string
    {
        return $this->id;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getSourceAccountId(): string
    {
        return $this->sourceAccountId;
    }

    public function getDestAccountId(): string
    {
        return $this->destAccountId;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function getInitiatedBy(): string
    {
        return $this->initiatedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
