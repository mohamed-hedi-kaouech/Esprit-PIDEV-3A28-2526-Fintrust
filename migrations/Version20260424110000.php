<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing behavioral analytics columns to users table';
    }

    public function up(Schema $schema): void
    {
        $usersTable = $schema->getTable('users');

        if (!$usersTable->hasColumn('budget_total')) {
            $this->addSql('ALTER TABLE users ADD budget_total NUMERIC(15, 2) DEFAULT NULL');
        }

        if (!$usersTable->hasColumn('behavior_updated_at')) {
            $this->addSql('ALTER TABLE users ADD behavior_updated_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $usersTable = $schema->getTable('users');

        if ($usersTable->hasColumn('budget_total')) {
            $this->addSql('ALTER TABLE users DROP budget_total');
        }

        if ($usersTable->hasColumn('behavior_updated_at')) {
            $this->addSql('ALTER TABLE users DROP behavior_updated_at');
        }
    }
}
