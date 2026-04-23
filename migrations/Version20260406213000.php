<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aligne la table notification avec le mapping Doctrine actuel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('RENAME TABLE notifications TO notification');
        $this->addSql('ALTER TABLE notification CHANGE type type VARCHAR(20) NOT NULL');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA76ED395');
        $this->addSql('DROP INDEX IDX_BF5476CAA76ED395 ON notification');
        $this->addSql('ALTER TABLE notification CHANGE type type VARCHAR(30) NOT NULL');
        $this->addSql('RENAME TABLE notification TO notifications');
    }
}
