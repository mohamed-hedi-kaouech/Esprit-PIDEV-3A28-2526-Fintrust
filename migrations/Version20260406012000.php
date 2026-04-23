<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406012000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute fraud_score et prépare le moteur global de risque utilisateur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD fraud_score DOUBLE PRECISION NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP fraud_score');
    }
}
