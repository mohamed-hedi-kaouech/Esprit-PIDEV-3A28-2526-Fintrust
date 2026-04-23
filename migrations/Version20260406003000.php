<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le token QR, les preferences utilisateur et les metriques comportementales/risque.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD qr_token VARCHAR(64) DEFAULT NULL, ADD preferred_language VARCHAR(5) NOT NULL DEFAULT 'fr', ADD theme_mode VARCHAR(10) NOT NULL DEFAULT 'light', ADD transaction_frequency DOUBLE PRECISION NOT NULL DEFAULT 0, ADD average_transaction_amount DOUBLE PRECISION NOT NULL DEFAULT 0, ADD risk_score DOUBLE PRECISION NOT NULL DEFAULT 0, ADD risk_level VARCHAR(20) NOT NULL DEFAULT 'LOW', ADD client_segment VARCHAR(20) NOT NULL DEFAULT 'STANDARD', ADD behavior_updated_at DATETIME DEFAULT NULL, CHANGE status status VARCHAR(20) NOT NULL");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E91AE26361 ON users (qr_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_1483A5E9E7927C74 ON users');
        $this->addSql('DROP INDEX UNIQ_1483A5E91AE26361 ON users');
        $this->addSql("ALTER TABLE users DROP qr_token, DROP preferred_language, DROP theme_mode, DROP transaction_frequency, DROP average_transaction_amount, DROP risk_score, DROP risk_level, DROP client_segment, DROP behavior_updated_at, CHANGE status status VARCHAR(255) NOT NULL");
    }
}
