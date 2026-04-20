<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts and transactions tables for fund transfer system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
                id          CHAR(36)        NOT NULL,
                owner_id    CHAR(36)        NOT NULL,
                currency    CHAR(3)         NOT NULL,
                balance     DECIMAL(20, 8)  NOT NULL DEFAULT '0.00000000',
                status      ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
                version     INT             NOT NULL DEFAULT 0,
                created_at  DATETIME(6)     NOT NULL,
                updated_at  DATETIME(6)     NOT NULL,
                CONSTRAINT pk_accounts PRIMARY KEY (id),
                CONSTRAINT chk_balance CHECK (balance >= 0),
                INDEX idx_owner (owner_id),
                INDEX idx_currency_status (currency, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE transactions (
                id               CHAR(36)        NOT NULL,
                idempotency_key  CHAR(36)        NOT NULL,
                source_account_id CHAR(36)       NOT NULL,
                dest_account_id  CHAR(36)        NOT NULL,
                amount           DECIMAL(20, 8)  NOT NULL,
                currency         CHAR(3)         NOT NULL,
                status           ENUM('pending','completed','failed','reversed') NOT NULL DEFAULT 'pending',
                failure_reason   VARCHAR(512)    NULL,
                initiated_by     VARCHAR(255)    NOT NULL,
                ip_address       VARCHAR(45)     NULL,
                created_at       DATETIME(6)     NOT NULL,
                completed_at     DATETIME(6)     NULL,
                CONSTRAINT pk_transactions PRIMARY KEY (id),
                CONSTRAINT uq_idempotency UNIQUE KEY (idempotency_key),
                CONSTRAINT chk_amount CHECK (amount > 0),
                INDEX idx_source  (source_account_id),
                INDEX idx_dest    (dest_account_id),
                INDEX idx_status  (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE accounts');
    }
}
