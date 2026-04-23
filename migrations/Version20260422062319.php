<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422062319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_login_audit DROP FOREIGN KEY FK_3D73F178A76ED395');
        $this->addSql('DROP TABLE ah');
        $this->addSql('DROP TABLE password_reset_audit_log');
        $this->addSql('DROP TABLE password_reset_request');
        $this->addSql('DROP TABLE security_events');
        $this->addSql('DROP TABLE user_login_audit');
        $this->addSql('ALTER TABLE item ADD quantite DOUBLE PRECISION DEFAULT 1, ADD tva DOUBLE PRECISION DEFAULT 0');
        $this->addSql('ALTER TABLE kyc CHANGE cin cin VARCHAR(8) NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE transaction_frequency transaction_frequency DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE average_transaction_amount average_transaction_amount DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE risk_score risk_score DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE fraud_score fraud_score DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ah (id INT NOT NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE password_reset_audit_log (id INT NOT NULL, password_reset_request_id INT DEFAULT NULL, event_type VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, channel VARCHAR(20) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, recovery_hash VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, request_ip VARCHAR(45) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, user_agent LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, context LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE password_reset_request (id INT NOT NULL, user_id INT DEFAULT NULL, public_id VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, recovery_hash VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, channel VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, secret_hash VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, expires_at DATETIME DEFAULT NULL, verified_at DATETIME DEFAULT NULL, used_at DATETIME DEFAULT NULL, attempts_count INT DEFAULT 0 NOT NULL, resend_count INT DEFAULT 0 NOT NULL, last_sent_at DATETIME DEFAULT NULL, status VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, request_ip VARCHAR(45) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, user_agent LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE security_events (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, ip VARCHAR(80) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, type VARCHAR(40) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, metadata VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE user_login_audit (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(190) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, success TINYINT(1) NOT NULL, reason VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_3D73F178A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE user_login_audit ADD CONSTRAINT FK_3D73F178A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE item DROP quantite, DROP tva');
        $this->addSql('ALTER TABLE kyc CHANGE cin cin VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE users CHANGE transaction_frequency transaction_frequency DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE average_transaction_amount average_transaction_amount DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE risk_score risk_score DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE fraud_score fraud_score DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
    }
}
