<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260410113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent read status to budget alerts';
    }

    public function up(Schema $schema): void
    {
        if ($schema->getTable('alerte')->hasColumn('read_status')) {
            return;
        }

        $this->addSql('ALTER TABLE alerte ADD read_status TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->getTable('alerte')->hasColumn('read_status')) {
            return;
        }

        $this->addSql('ALTER TABLE alerte DROP read_status');
    }
}
