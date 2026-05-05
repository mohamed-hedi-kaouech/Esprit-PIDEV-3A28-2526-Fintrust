<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore password reset request and audit log tables required by the password reset flow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS password_reset_request (
    id INT AUTO_INCREMENT NOT NULL,
    user_id INT DEFAULT NULL,
    public_id VARCHAR(64) NOT NULL,
    recovery_hash VARCHAR(64) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    secret_hash VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    used_at DATETIME DEFAULT NULL,
    attempts_count INT DEFAULT 0 NOT NULL,
    resend_count INT DEFAULT 0 NOT NULL,
    last_sent_at DATETIME DEFAULT NULL,
    status VARCHAR(20) NOT NULL,
    request_ip VARCHAR(45) DEFAULT NULL,
    user_agent LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX UNIQ_PASSWORD_RESET_PUBLIC_ID (public_id),
    INDEX idx_password_reset_public_id (public_id),
    INDEX idx_password_reset_channel_status (channel, status),
    INDEX idx_password_reset_recovery_hash (recovery_hash),
    INDEX IDX_PASSWORD_RESET_REQUEST_USER (user_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS password_reset_audit_log (
    id INT AUTO_INCREMENT NOT NULL,
    password_reset_request_id INT DEFAULT NULL,
    event_type VARCHAR(50) NOT NULL,
    channel VARCHAR(20) DEFAULT NULL,
    recovery_hash VARCHAR(64) DEFAULT NULL,
    request_ip VARCHAR(45) DEFAULT NULL,
    user_agent LONGTEXT DEFAULT NULL,
    context LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_password_reset_audit_event (event_type, created_at),
    INDEX idx_password_reset_audit_recovery (recovery_hash, created_at),
    INDEX IDX_PASSWORD_RESET_AUDIT_REQUEST (password_reset_request_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
ALTER TABLE password_reset_request
    ADD CONSTRAINT FK_PASSWORD_RESET_REQUEST_USER
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE SET NULL
SQL);

        $this->addSql(<<<'SQL'
ALTER TABLE password_reset_audit_log
    ADD CONSTRAINT FK_PASSWORD_RESET_AUDIT_REQUEST
    FOREIGN KEY (password_reset_request_id) REFERENCES password_reset_request (id)
    ON DELETE SET NULL
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE password_reset_audit_log DROP FOREIGN KEY FK_PASSWORD_RESET_AUDIT_REQUEST');
        $this->addSql('ALTER TABLE password_reset_request DROP FOREIGN KEY FK_PASSWORD_RESET_REQUEST_USER');
        $this->addSql('DROP TABLE password_reset_audit_log');
        $this->addSql('DROP TABLE password_reset_request');
    }
}
