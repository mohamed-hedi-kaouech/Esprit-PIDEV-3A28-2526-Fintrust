<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260414113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les champs de reponse admin sur les feedbacks de publication.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('feedback', 'admin_response')) {
            $this->addSql('ALTER TABLE feedback ADD admin_response LONGTEXT DEFAULT NULL');
        }

        if (!$this->columnExists('feedback', 'admin_response_date')) {
            $this->addSql('ALTER TABLE feedback ADD admin_response_date DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('feedback', 'admin_response_date')) {
            $this->addSql('ALTER TABLE feedback DROP COLUMN admin_response_date');
        }

        if ($this->columnExists('feedback', 'admin_response')) {
            $this->addSql('ALTER TABLE feedback DROP COLUMN admin_response');
        }
    }

    private function tableExists(string $tableName): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$tableName]);
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        if (!$this->tableExists($tableName)) {
            return false;
        }

        $columns = $this->connection->createSchemaManager()->listTableColumns($tableName);

        return array_key_exists($columnName, $columns);
    }
}
