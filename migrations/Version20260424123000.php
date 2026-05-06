<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les colonnes Yousign manquantes a la table cheque si elles n existent pas.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('cheque');

        if (!$table->hasColumn('yousign_procedure_id')) {
            $this->addSql('ALTER TABLE cheque ADD yousign_procedure_id VARCHAR(255) DEFAULT NULL');
        }

        if (!$table->hasColumn('yousign_status')) {
            $this->addSql('ALTER TABLE cheque ADD yousign_status VARCHAR(50) DEFAULT NULL');
        }

        if (!$table->hasColumn('yousign_signing_link')) {
            $this->addSql('ALTER TABLE cheque ADD yousign_signing_link LONGTEXT DEFAULT NULL');
        }

        if (!$table->hasColumn('yousign_signed_at')) {
            $this->addSql('ALTER TABLE cheque ADD yousign_signed_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('cheque');

        if ($table->hasColumn('yousign_signed_at')) {
            $this->addSql('ALTER TABLE cheque DROP COLUMN yousign_signed_at');
        }

        if ($table->hasColumn('yousign_signing_link')) {
            $this->addSql('ALTER TABLE cheque DROP COLUMN yousign_signing_link');
        }

        if ($table->hasColumn('yousign_status')) {
            $this->addSql('ALTER TABLE cheque DROP COLUMN yousign_status');
        }

        if ($table->hasColumn('yousign_procedure_id')) {
            $this->addSql('ALTER TABLE cheque DROP COLUMN yousign_procedure_id');
        }
    }
}
