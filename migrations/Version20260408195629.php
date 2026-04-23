<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260408195629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alerte ADD read_status TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE transaction_frequency transaction_frequency DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE average_transaction_amount average_transaction_amount DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE risk_score risk_score DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE fraud_score fraud_score DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alerte DROP read_status');
        $this->addSql('ALTER TABLE users CHANGE transaction_frequency transaction_frequency DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE average_transaction_amount average_transaction_amount DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE risk_score risk_score DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE fraud_score fraud_score DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
    }
}
