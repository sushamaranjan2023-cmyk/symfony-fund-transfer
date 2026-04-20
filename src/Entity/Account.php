<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InsufficientFundsException;
use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\Index(columns: ['owner_id'], name: 'idx_owner')]
#[ORM\Index(columns: ['currency', 'status'], name: 'idx_currency_status')]
#[ORM\HasLifecycleCallbacks]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $ownerId;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    /**
     * Stored as string to preserve DECIMAL(20,8) precision from MySQL.
     * Never cast to float. Use bcmath for all arithmetic.
     */
    #[ORM\Column(type: 'decimal', precision: 20, scale: 8)]
    private string $balance;

    #[ORM\Column(type: 'string', enumType: AccountStatus::class)]
    private AccountStatus $status;

    /**
     * Optimistic lock version — available for future use.
     * Pessimistic locking (SELECT FOR UPDATE) is the primary mechanism here.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $ownerId,
        string $currency,
        string $initialBalance = '0.00000000',
    ) {
        $this->id        = (string) $id;
        $this->ownerId   = $ownerId;
        $this->currency  = strtoupper($currency);
        $this->balance   = $initialBalance;
        $this->status    = AccountStatus::Active;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Debit the account. Throws InsufficientFundsException if balance would go negative.
     * Uses bcmath for exact decimal arithmetic — never floats.
     */
    public function debit(string $amount): void
    {
        $newBalance = bcsub($this->balance, $amount, 8);

        if (bccomp($newBalance, '0', 8) < 0) {
            throw new InsufficientFundsException(
                (string) $this->id,
                $this->balance,
                $amount,
                $this->currency
            );
        }

        $this->balance   = $newBalance;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Credit the account. Amount must be positive.
     */
    public function credit(string $amount): void
    {
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        $this->balance   = bcadd($this->balance, $amount, 8);
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Getters ──────────────────────────────────────────────────────────────

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    public function getStatus(): AccountStatus
    {
        return $this->status;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function freeze(): void
    {
        $this->status    = AccountStatus::Frozen;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }
}
