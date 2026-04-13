<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insert default publication categories when the table exists';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('publication_category')) {
            return;
        }

        $this->addSql("INSERT INTO `publication_category` (`name`, `color`, `icon`) VALUES
            ('OFFRES', '#3b82f6', 'gift'),
            ('OCCASIONS', '#10b981', 'star'),
            ('OPPORTUNITES', '#f59e0b', 'rocket'),
            ('CREDITS', '#8b5cf6', 'credit-card'),
            ('INVESTISSEMENTS', '#dc2626', 'chart-line'),
            ('ASSURANCES', '#06b6d4', 'shield'),
            ('NOUVEAUTES', '#ec4899', 'sparkles'),
            ('AUTRES', '#6b7280', 'bookmark')
        ");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('publication_category')) {
            return;
        }

        $this->addSql("DELETE FROM `publication_category` WHERE `name` IN ('OFFRES', 'OCCASIONS', 'OPPORTUNITES', 'CREDITS', 'INVESTISSEMENTS', 'ASSURANCES', 'NOUVEAUTES', 'AUTRES')");
    }
}
