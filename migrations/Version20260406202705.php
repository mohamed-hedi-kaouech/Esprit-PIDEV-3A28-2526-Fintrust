<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406202705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migration neutralisee: doublon d un snapshot complet deja couvert par les migrations precedentes.';
    }

    public function up(Schema $schema): void
    {
        // Intentionally left blank.
    }

    public function down(Schema $schema): void
    {
        // Intentionally left blank.
    }
}
